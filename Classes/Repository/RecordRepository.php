<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use Override;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

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
}
