<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repository for accessing system category elements stored in the database.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class CategoryRepository
{
    /**
     * TYPO3 database connection pool for direct database operations.
     *
     * This property provides access to database connections for performing
     * optimized database operations. It is used to create query builders
     * for retrieving content elements from the tt_content table.
     *
     * @var ConnectionPool
     */
    private ConnectionPool $connectionPool;

    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections for different tables.
     *
     * @param ConnectionPool $connectionPool The TYPO3 database connection pool
     */
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Returns the system categories assigned to a record.
     *
     * @param string $tableName
     * @param int    $uid
     *
     * @return array<array-key, string>
     *
     * @throws Exception
     */
    public function findAssignedToRecord(string $tableName, int $uid): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($tableName);

        return $queryBuilder
            ->select('sc.uid', 'sc.title')
            ->from('sys_category', 'sc')
            ->leftJoin('sc', 'sys_category_record_mm', 'mm', 'mm.uid_local = sc.uid')
            ->where(
                $queryBuilder->expr()->eq(
                    'mm.uid_foreign',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'mm.tablenames',
                    $queryBuilder->createNamedParameter($tableName)
                ),
                $queryBuilder->expr()->eq(
                    'mm.fieldname',
                    $queryBuilder->quote('categories')
                )
            )
            ->orderBy('sc.title')
            ->executeQuery()
            ->fetchAllKeyValue();
    }
}
