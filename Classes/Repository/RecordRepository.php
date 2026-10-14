<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use Doctrine\DBAL\ArrayParameterType;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use Override;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_chunk;
use function array_map;
use function array_push;
use function array_shift;
use function array_unique;
use function array_values;
use function in_array;
use function intval;
use function is_string;
use function strval;

/**
 * Repository for accessing generic database records across different tables.
 *
 * This repository provides methods for working with database records regardless
 * of their specific table type. It offers utility functions for:
 * - Finding the parent page ID (pid) for any record
 * - Performing basic record lookups across different tables
 *
 * The repository is used by various components of the indexing system to determine
 * the context of records (e.g., which page a record belongs to) and to establish
 * relationships between different types of records in the database.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class RecordRepository implements RecordRepositoryInterface
{
    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections needed for
     * retrieving record information from any table in the database.
     *
     * @param ConnectionPool $connectionPool The TYPO3 database connection pool
     */
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Finds the parent page ID (pid) for any record in the TYPO3 database.
     *
     * This method retrieves the page ID that a specific record belongs to by
     * looking up its 'pid' field in the database. This information is essential for:
     * - Determining the context of a record (which page it's located on)
     * - Finding the root page for a record (by passing the result to PageRepository::getRootPageId)
     * - Establishing relationships between records and pages
     *
     * The method works with any table that follows TYPO3's standard data model
     * where records have a 'pid' field referencing their parent page.
     *
     * @param string $tableName The database table name of the record
     * @param int    $recordUid The unique identifier of the record
     *
     * @return int|false The parent page ID (pid) of the record, or false if the record doesn't exist
     */
    #[Override]
    public function findPid(string $tableName, int $recordUid): int|false
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($tableName);

        $queryBuilder->getRestrictions()
            ->removeAll();

        $record = $queryBuilder
            ->select('pid')
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $recordUid,
                        Connection::PARAM_INT
                    )
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        return $record['pid'] ?? false;
    }

    /**
     * Finds the translations of the given records.
     *
     * Only the deleted state and the workspace are restrictions here, so the live
     * translations are returned, hidden ones included.
     *
     * @param string $tableName  The database table name of the records
     * @param int[]  $recordUids The unique identifiers of the records
     *
     * @return int[] The UIDs of the translations, empty for a table without a translation field
     */
    #[Override]
    public function findTranslationUids(
        string $tableName,
        array $recordUids,
    ): array {
        $field = $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] ?? null;

        if (
            !is_string($field)
            || ($field === '')
        ) {
            return [];
        }

        return $this->findUidsWhere($tableName, $field, $recordUids);
    }

    /**
     * Finds the child records of the inline relations of the given records.
     *
     * @param string   $tableName   The database table name of the parent records
     * @param int[]    $recordUids  The unique identifiers of the parent records
     * @param string[] $childTables The tables whose records are of interest
     *
     * @return array<string, int[]> The UIDs of the children by table, empty if there are none
     */
    private function findInlineChildUids(
        string $tableName,
        array $recordUids,
        array $childTables,
    ): array {
        /** @var array<string, array<int, int>> $children */
        $children = [];

        /** @var list<array{string, int[]}> $parents */
        $parents = [[$tableName, array_values(array_unique($recordUids))]];

        while ($parents !== []) {
            [$parentTable, $parentUids] = array_shift($parents);

            if ($parentUids === []) {
                continue;
            }

            foreach ($GLOBALS['TCA'][$parentTable]['columns'] ?? [] as $column) {
                $config       = $column['config'] ?? [];
                $foreignTable = $config['foreign_table'] ?? '';
                $foreignField = $config['foreign_field'] ?? '';

                if (($config['type'] ?? '') !== 'inline') {
                    continue;
                }

                if (!is_string($foreignField)) {
                    continue;
                }

                if ($foreignField === '') {
                    continue;
                }

                if (!in_array($foreignTable, $childTables, true)) {
                    continue;
                }

                if (!isset($GLOBALS['TCA'][$foreignTable])) {
                    continue;
                }

                if (($config['foreign_table_field'] ?? '') !== '') {
                    continue;
                }

                $newUids = [];

                foreach ($this->findUidsWhere($foreignTable, $foreignField, $parentUids) as $childUid) {
                    if (isset($children[$foreignTable][$childUid])) {
                        continue;
                    }

                    $children[$foreignTable][$childUid] = $childUid;
                    $newUids[]                          = $childUid;
                }

                if ($newUids !== []) {
                    $parents[] = [$foreignTable, $newUids];
                }
            }
        }

        return array_map(array_values(...), $children);
    }

    /**
     * Finds the records that are stored on the given pages.
     *
     * Only the deleted state and the workspace are restrictions here, so the live
     * records are returned, hidden ones and ones outside of their start and end
     * time included.
     *
     * @param string $tableName The database table name of the records
     * @param int[]  $pageIds   The UIDs of the pages
     *
     * @return int[] The UIDs of the records, empty for a table that does not exist
     */
    #[Override]
    public function findUidsByPids(
        string $tableName,
        array $pageIds,
    ): array {
        if (!isset($GLOBALS['TCA'][$tableName])) {
            return [];
        }

        return $this->findUidsWhere($tableName, 'pid', $pageIds);
    }

    /**
     * Finds the records of one language that are stored on a page.
     *
     * Only the deleted state and the workspace are restrictions here. DataHandler
     * deletes these records together with the translation of the page.
     *
     * @param string $tableName   The database table name of the records
     * @param int    $pageId      The UID of the page the records are stored on
     * @param int    $languageUid The UID of the language
     *
     * @return int[] The UIDs of the records, empty for a table that cannot be localized
     */
    #[Override]
    public function findUidsByPidAndLanguage(
        string $tableName,
        int $pageId,
        int $languageUid,
    ): array {
        $languageField = $GLOBALS['TCA'][$tableName]['ctrl']['languageField'] ?? null;
        $parentField   = $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] ?? null;

        // DataHandler only deletes the records of a table that can be localized, which
        // needs the field of the translation parent next to the language field
        if (
            !is_string($languageField)
            || ($languageField === '')
            || !is_string($parentField)
            || ($parentField === '')
        ) {
            return [];
        }

        return $this->findUidsWhere(
            $tableName,
            'pid',
            [$pageId],
            [$languageField => $languageUid],
        );
    }

    /**
     * Returns those of the given records that are not deleted.
     *
     * @param string $tableName  The database table name of the records
     * @param int[]  $recordUids The unique identifiers of the records
     *
     * @return int[] The UIDs of the records that still exist
     */
    #[Override]
    public function findExistingUids(
        string $tableName,
        array $recordUids,
    ): array {
        if (!isset($GLOBALS['TCA'][$tableName])) {
            return [];
        }

        return $this->findUidsWhere($tableName, 'uid', $recordUids);
    }

    /**
     * Finds the UIDs of the not deleted records whose field has one of the given values.
     *
     * @param string             $tableName The database table name of the records
     * @param string             $field     The name of the field to compare, an integer or string column
     * @param int[]              $values    The values to look for
     * @param array<string, int> $equals    Further integer fields that have to equal a value
     *
     * @return int[] The UIDs of the records
     */
    #[Override]
    public function findUidsWhere(
        string $tableName,
        string $field,
        array $values,
        array $equals = [],
    ): array {
        $uids = [];

        foreach (array_chunk($values, Constants::IN_LIST_BLOCK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool
                ->getQueryBuilderForTable($tableName);

            // The live records only, a workspace version carries the parent of its live
            // record and is no document of the index
            $queryBuilder
                ->getRestrictions()
                ->removeByType(HiddenRestriction::class)
                ->removeByType(StartTimeRestriction::class)
                ->removeByType(EndTimeRestriction::class)
                ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));

            // The values are bound as strings, because the parent field of an inline relation
            // can be a string column, which a comparison with integers would read in full
            $queryBuilder
                ->select('uid')
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->in(
                        $field,
                        $queryBuilder->createNamedParameter(
                            array_map(strval(...), $chunk),
                            ArrayParameterType::STRING,
                        )
                    )
                );

            foreach ($equals as $equalsField => $equalsValue) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq(
                        $equalsField,
                        $queryBuilder->createNamedParameter($equalsValue, Connection::PARAM_INT)
                    )
                );
            }

            $rows = $queryBuilder
                ->executeQuery()
                ->fetchFirstColumn();

            array_push($uids, ...array_map(intval(...), $rows));
        }

        return array_values(array_unique($uids));
    }

    /**
     * Finds what DataHandler deletes or moves together with a record without a command of its own.
     *
     * These are the translations of the record, the children of its inline relations and of
     * the relations of its translations, and the translations of those children. Children that
     * DataHandler leaves in place are returned as well. The callers check existence after a
     * delete and decide them again after a move.
     *
     * @param string   $tableName   The database table name of the record
     * @param int      $recordUid   The UID of the record
     * @param string[] $childTables The tables whose children are looked for, the table of the record included
     *
     * @return array<string, int[]> The UIDs of the dependent records by table
     */
    #[Override]
    public function findDependentUids(
        string $tableName,
        int $recordUid,
        array $childTables,
    ): array {
        $translationUids = $this->findTranslationUids($tableName, [$recordUid]);

        $records = [
            $tableName => $translationUids,
        ];

        // A translation has inline children of its own
        $parentUids = [$recordUid, ...$translationUids];

        foreach ($this->findInlineChildUids($tableName, $parentUids, $childTables) as $childTable => $childUids) {
            $records[$childTable] = array_values(
                array_unique(
                    [
                        ...($records[$childTable] ?? []),
                        ...$childUids,
                        ...$this->findTranslationUids($childTable, $childUids),
                    ]
                )
            );
        }

        return $records;
    }
}
