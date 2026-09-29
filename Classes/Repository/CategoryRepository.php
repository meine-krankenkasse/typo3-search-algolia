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
use Doctrine\DBAL\Exception;
use Override;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repository for accessing system category elements stored in the database.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class CategoryRepository implements CategoryLookupInterface
{
    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections for different tables.
     *
     * @param ConnectionPool $connectionPool The TYPO3 database connection pool
     */
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Returns the system categories assigned to a record.
     *
     * @param string $tableName
     * @param int    $uid
     * @param string $fieldName The sys_category_record_mm fieldname to filter by
     *
     * @return array<array-key, array<string, int|string|null>>
     *
     * @throws Exception
     */
    #[Override]
    public function findAssignedToRecord(string $tableName, int $uid, string $fieldName = 'categories'): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($tableName);

        return $queryBuilder
            ->select('sc.*')
            ->from(
                'sys_category',
                'sc'
            )
            ->leftJoin(
                'sc',
                'sys_category_record_mm',
                'mm',
                'mm.uid_local = sc.uid'
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'mm.uid_foreign',
                    $queryBuilder->createNamedParameter(
                        $uid,
                        Connection::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->eq(
                    'mm.tablenames',
                    $queryBuilder->createNamedParameter($tableName)
                ),
                $queryBuilder->expr()->eq(
                    'mm.fieldname',
                    $queryBuilder->createNamedParameter($fieldName)
                )
            )
            ->orderBy('sc.title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Returns a single system category by its UID.
     *
     * @param int $uid The UID of the category to find
     *
     * @return array<string, int|string|null>|false The category record, or false if not found
     *
     * @throws Exception
     */
    #[Override]
    public function findByUid(int $uid): array|false
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_category');

        return $queryBuilder
            ->select('*')
            ->from('sys_category')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * Checks if a record in the specified table has a relationship to any of the given category UIDs.
     *
     * This method queries the `sys_category_record_mm` table to determine if there is at least
     * one matching category reference for the specified record and table.
     *
     * @param int    $uid          The UID of the record to check for category associations
     * @param string $tableName    The name of the table to which the record belongs
     * @param int[]  $categoryUids An array of category UIDs to match against
     *
     * @return bool True if a category reference exists for the given criteria, false otherwise
     */
    #[Override]
    public function hasCategoryReference(int $uid, string $tableName, array $categoryUids): bool
    {
        if ($categoryUids === []) {
            return false;
        }

        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_category_record_mm');

        $matchingRecord = $queryBuilder
            ->select('uid_local')
            ->from('sys_category_record_mm')
            ->where(
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter($tableName)
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter('categories')
                ),
                $queryBuilder->expr()->eq(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter(
                        $uid,
                        Connection::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->in(
                    'uid_local',
                    $queryBuilder->createNamedParameter(
                        $categoryUids,
                        ArrayParameterType::INTEGER
                    )
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $matchingRecord !== false;
    }

    /**
     * Returns the UIDs of all records the given categories are assigned to
     * through sys_category_record_mm, regardless of the MM fieldname, grouped
     * by table.
     *
     * @param int[] $categoryUids The UIDs of the categories
     *
     * @return array<string, list<int>> The record UIDs, keyed by table name
     *
     * @throws Exception
     */
    public function findRecordUidsByCategories(array $categoryUids): array
    {
        if ($categoryUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_category_record_mm');

        $rows = $queryBuilder
            ->select(
                'tablenames',
                'uid_foreign'
            )
            ->from('sys_category_record_mm')
            ->where(
                $queryBuilder->expr()->in(
                    'uid_local',
                    $queryBuilder->createNamedParameter(
                        $categoryUids,
                        ArrayParameterType::INTEGER
                    )
                )
            )
            ->groupBy(
                'tablenames',
                'uid_foreign'
            )
            ->orderBy('tablenames')
            ->addOrderBy('uid_foreign')
            ->executeQuery()
            ->fetchAllAssociative();

        $recordUids = [];

        foreach ($rows as $row) {
            $recordUids[(string) $row['tablenames']][] = (int) $row['uid_foreign'];
        }

        return $recordUids;
    }

    /**
     * Returns the UIDs of all subcategories of a category, on any level below
     * it. Deleted, hidden and start/endtime-restricted categories and their
     * subtrees are left out. This assumes documents carry a category's title
     * path only up to its first category that is not visible, so a change
     * above such a category cannot change them. A cyclic parent reference
     * ends the walk instead of looping.
     *
     * @param int $categoryUid The UID of the category
     *
     * @return list<int> The subcategory UIDs, level by level
     *
     * @throws Exception
     */
    public function findDescendantUids(int $categoryUid): array
    {
        $visitedUids    = [$categoryUid => true];
        $descendantUids = [];
        $parentUids     = [$categoryUid];

        while ($parentUids !== []) {
            $queryBuilder = $this->connectionPool
                ->getQueryBuilderForTable('sys_category');

            $childUids = $queryBuilder
                ->select('uid')
                ->from('sys_category')
                ->where(
                    $queryBuilder->expr()->in(
                        'parent',
                        $queryBuilder->createNamedParameter(
                            $parentUids,
                            ArrayParameterType::INTEGER
                        )
                    )
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchFirstColumn();

            $parentUids = [];

            foreach ($childUids as $childUid) {
                $childUid = (int) $childUid;

                if (isset($visitedUids[$childUid])) {
                    continue;
                }

                $visitedUids[$childUid] = true;
                $descendantUids[]       = $childUid;
                $parentUids[]           = $childUid;
            }
        }

        return $descendantUids;
    }
}
