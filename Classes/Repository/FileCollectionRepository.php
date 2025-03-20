<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Collection\AbstractFileCollection;

/**
 * Repository for accessing file collections stored in the database.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class FileCollectionRepository extends \TYPO3\CMS\Core\Resource\FileCollectionRepository
{
    /**
     * @var ConnectionPool
     */
    private readonly ConnectionPool $connectionPool;

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
     * @param int[] $collectionIds
     *
     * @return AbstractFileCollection[]
     */
    public function findAllByCollections(array $collectionIds = []): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($this->table);

        $constraints = [];

        if ($collectionIds !== []) {
            $constraints[] = $queryBuilder->expr()->in('uid', $collectionIds);
        }

        return $this->queryMultipleRecords($constraints) ?? [];
    }
}
