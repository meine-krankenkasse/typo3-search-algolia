<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The record repository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordRepository
{
    /**
     * @var ConnectionPool
     */
    private ConnectionPool $connectionPool;

    /**
     * Constructor.
     *
     * @param ConnectionPool $connectionPool
     */
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Returns the page ID for the given table and record UID.
     *
     * @param string $tableName The table name
     * @param int    $recordUid The record UID
     *
     * @return int|false
     */
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
