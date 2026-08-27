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
use TYPO3\CMS\Core\Collection\CollectionInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Collection\AbstractFileCollection;
use TYPO3\CMS\Core\Resource\Collection\FileCollectionRegistry;

use function array_filter;
use function array_values;

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
readonly class FileCollectionRepository extends \TYPO3\CMS\Core\Resource\FileCollectionRepository
{
    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections needed for
     * retrieving file collections. The parent constructor is called
     * explicitly because the parent's own methods (e.g. the inherited
     * queryMultipleRecords() used by findAllByCollectionUids()) rely on
     * its own private $connectionPool/$fileCollectionRegistry properties,
     * which stay uninitialized otherwise.
     *
     * @param ConnectionPool         $connectionPool         The TYPO3 database connection pool
     * @param FileCollectionRegistry $fileCollectionRegistry The TYPO3 file collection registry
     */
    public function __construct(
        private ConnectionPool $connectionPool,
        FileCollectionRegistry $fileCollectionRegistry,
    ) {
        parent::__construct($this->connectionPool, $fileCollectionRegistry);
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
     * The parent's queryMultipleRecords() is typed to the generic CollectionInterface
     * since TYPO3 v14, but every 'sys_file_collection' record resolves through
     * FileCollectionRegistry to an AbstractFileCollection subclass by TYPO3's own
     * convention. Narrow it back here rather than trusting the widened interface,
     * since callers (e.g. FileIndexer) rely on AbstractFileCollection-specific
     * methods like loadContents().
     *
     * @param int[] $collectionIds Array of file collection UIDs to retrieve
     *
     * @return AbstractFileCollection[] Array of file collection objects
     */
    public function findAllByCollectionUids(array $collectionIds = []): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file_collection');

        $constraints = [];

        if ($collectionIds !== []) {
            $constraints[] = $queryBuilder->expr()->in('uid', $collectionIds);
        }

        $collections = $this->queryMultipleRecords($constraints) ?? [];

        return array_values(
            array_filter(
                $collections,
                static fn (CollectionInterface $collection): bool => $collection instanceof AbstractFileCollection,
            ),
        );
    }

    /**
     * Retrieves collection data for the given collection IDs.
     *
     * This method fetches records from the repository's table based on the
     * provided collection IDs. If no IDs are passed, an empty array is returned.
     * The resulting data includes specific details for each collection.
     *
     * @param int[] $collectionIds An array of collection IDs to fetch data for
     *
     * @return list<array{uid: int, type: string, folder_identifier: string, recursive: int, category: int}>
     */
    public function getCollectionDataByIds(array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file_collection');

        /** @var list<array{uid: int, type: string, folder_identifier: string, recursive: int, category: int}> $rows */
        $rows = $queryBuilder
            ->select(
                'uid',
                'type',
                'folder_identifier',
                'recursive',
                'category',
            )
            ->from('sys_file_collection')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $collectionIds,
                        ArrayParameterType::INTEGER,
                    ),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }
}
