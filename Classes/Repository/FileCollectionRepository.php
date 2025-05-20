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
 * Repository for accessing and filtering file collections stored in the database.
 *
 * This repository extends TYPO3's core FileCollectionRepository to provide
 * additional functionality specific to the search indexing system. It offers:
 * - Methods for retrieving file collections by their UIDs
 * - Access to files within collections for indexing purposes
 *
 * File collections are used by the indexing system to determine which files
 * should be included in the search index. This repository helps retrieve those
 * collections and their contents efficiently.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class FileCollectionRepository extends \TYPO3\CMS\Core\Resource\FileCollectionRepository
{
    /**
     * TYPO3 database connection pool for direct database operations.
     *
     * This property provides access to database connections for performing
     * optimized database queries on file collections. It is used to create
     * query builders for retrieving file collections from the database.
     *
     * @var ConnectionPool
     */
    private readonly ConnectionPool $connectionPool;

    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections needed for
     * retrieving file collections.
     *
     * @param ConnectionPool $connectionPool The TYPO3 database connection pool
     */
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Retrieves file collections by their unique identifiers.
     *
     * This method fetches file collection objects based on their UIDs. It extends
     * the parent class functionality by allowing filtering for specific collection IDs.
     * If no collection IDs are provided, all available collections will be returned.
     *
     * The method is primarily used by the file indexer to retrieve collections
     * that have been configured for indexing in the indexing service settings.
     * These collections define which files should be included in the search index.
     *
     * @param int[] $collectionIds Array of file collection UIDs to retrieve
     *
     * @return AbstractFileCollection[] Array of file collection objects
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
