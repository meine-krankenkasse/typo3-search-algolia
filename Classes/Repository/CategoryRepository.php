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
     *
     * @return array<array-key, array<string, int|string|null>>
     *
     * @throws Exception
     */
    public function findAssignedToRecord(string $tableName, int $uid): array
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
                    $queryBuilder->quote('categories')
                )
            )
            ->orderBy('sc.title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Checks if a record is assigned to any of the given category UIDs.
     *
     * @param int    $uid          UID of the record
     * @param string $tableName    Name of the table the record belongs to
     * @param int[]  $categoryUids List of sys_category UIDs
     *
     * @return bool
     */
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
}
