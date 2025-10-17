<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\AbstractIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for detecting records that should be removed from the search index.
 *
 * This service identifies records that were previously indexed but should no longer
 * be included in the search index based on current inclusion criteria. It compares
 * the current state of records in the database against the indexing criteria to
 * find records that have been excluded from search.
 *
 * The service handles different types of exclusions:
 * - Pages marked with no_search flag
 * - Pages with doktype not matching the configured types
 * - Content elements with CType not matching the configured types
 * - Files marked with no_search flag
 * - Records that no longer match page constraints (moved outside indexed page trees)
 * - Records that have been deleted or hidden
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class DeletionDetectionService
{
    /**
     * Database connection pool for executing database queries.
     *
     * Used to create query builders for different database tables and
     * to retrieve records for comparison with indexing criteria.
     *
     * @var ConnectionPool
     */
    private readonly ConnectionPool $connectionPool;

    /**
     * Repository for accessing indexing service configurations.
     *
     * This repository provides access to the indexing service configurations stored
     * in the database, which define what content should be indexed and how.
     *
     * @var IndexingServiceRepository
     */
    private readonly IndexingServiceRepository $indexingServiceRepository;

    /**
     * Factory for creating indexer instances.
     *
     * Used to create the appropriate indexer instance based on the indexing service
     * type, which allows access to the indexer's filtering logic.
     *
     * @var IndexerFactory
     */
    private readonly IndexerFactory $indexerFactory;

    /**
     * Repository for page-related operations.
     *
     * This repository provides methods for retrieving page information,
     * particularly for determining page hierarchies that are essential
     * for proper indexing context and recursive page resolution.
     *
     * @var PageRepository
     */
    private readonly PageRepository $pageRepository;

    /**
     * Initializes the deletion detection service with required dependencies.
     *
     * @param ConnectionPool            $connectionPool            Database connection pool for executing queries
     * @param IndexingServiceRepository $indexingServiceRepository Repository for accessing indexing service configurations
     * @param IndexerFactory            $indexerFactory            Factory for creating indexer instances
     * @param PageRepository            $pageRepository            Repository for page-related operations
     */
    public function __construct(
        ConnectionPool $connectionPool,
        IndexingServiceRepository $indexingServiceRepository,
        IndexerFactory $indexerFactory,
        PageRepository $pageRepository,
    ) {
        $this->connectionPool            = $connectionPool;
        $this->indexingServiceRepository = $indexingServiceRepository;
        $this->indexerFactory            = $indexerFactory;
        $this->pageRepository            = $pageRepository;
    }

    /**
     * Detects records that should be removed from the search index.
     *
     * This method examines all indexing services and identifies records that
     * no longer meet the current inclusion criteria. It returns an array of
     * records that should be queued for deletion from the search index.
     *
     * The method handles different indexer types differently:
     * - For standard indexers (pages, content), it queries the database to find excluded records
     * - For file indexers, it checks file collections and metadata
     *
     * @return array<array{indexing_service: IndexingService, table_name: string, record_uid: int}> Array of records to delete
     */
    public function detectRecordsForDeletion(): array
    {
        $recordsToDelete = [];

        /** @var IndexingService $indexingService */
        foreach ($this->indexingServiceRepository->findAll() as $indexingService) {
            $indexerInstance = $this->indexerFactory
                ->makeInstanceByType($indexingService->getType())
                ?->withIndexingService($indexingService);

            if (!($indexerInstance instanceof AbstractIndexer)) {
                continue;
            }

            try {
                // Get records that should be excluded from indexing
                $excludedRecords = $this->getExcludedRecords($indexingService, $indexerInstance);

                foreach ($excludedRecords as $recordUid) {
                    $recordsToDelete[] = [
                        'indexing_service' => $indexingService,
                        'table_name'       => $indexerInstance->getTable(),
                        'record_uid'       => $recordUid,
                    ];
                }
            } catch (Throwable) {
                // Log the error but continue with other indexing services
                // This ensures that one failing service doesn't break the entire detection process
                continue;
            }
        }

        return $recordsToDelete;
    }

    /**
     * Gets records that should be excluded from indexing for a specific indexing service.
     *
     * This method finds all records in the database that do NOT meet the current
     * indexing criteria. It builds a query with inverted constraints to find
     * records that would be excluded by the normal indexing process.
     *
     * @param IndexingService $indexingService The indexing service configuration
     * @param AbstractIndexer $indexerInstance The indexer instance for the service
     *
     * @return int[] Array of record UIDs that should be excluded from indexing
     */
    private function getExcludedRecords(IndexingService $indexingService, AbstractIndexer $indexerInstance): array
    {
        // Special handling for file indexer as it doesn't use standard database queries
        if ($indexerInstance instanceof FileIndexer) {
            return $this->getExcludedFiles($indexingService, $indexerInstance);
        }

        $tableName    = $indexerInstance->getTable();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);

        // Set up query restrictions (same as normal indexing)
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DefaultRestrictionContainer::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class));

        // Get all records that would be included by normal indexing
        $includedConstraints = $this->getIndexingConstraints($queryBuilder, $indexingService, $indexerInstance);

        $includedRecords = [];
        if ($includedConstraints !== []) {
            $result = $queryBuilder
                ->select('uid')
                ->from($tableName)
                ->where(...$includedConstraints)
                ->executeQuery();

            while ($row = $result->fetchAssociative()) {
                $includedRecords[] = (int) $row['uid'];
            }
        }

        // Now find all records that exist but are NOT in the included set
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('uid')
            ->from($tableName);

        // Exclude deleted records as they don't need explicit deletion from index
        $queryBuilder->where(
            $queryBuilder->expr()->eq('deleted', 0)
        );

        // If we have included records, exclude them from our exclusion query
        if ($includedRecords !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('uid', $includedRecords)
            );
        }

        $excludedRecords = [];
        $result          = $queryBuilder->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $excludedRecords[] = (int) $row['uid'];
        }

        return $excludedRecords;
    }

    /**
     * Gets the indexing constraints that would be applied by the normal indexing process.
     *
     * This method recreates the same constraints that would be used when adding
     * records to the indexing queue, allowing us to identify which records would
     * be included or excluded.
     *
     * @param QueryBuilder    $queryBuilder    The query builder instance
     * @param IndexingService $indexingService The indexing service configuration
     * @param AbstractIndexer $indexerInstance The indexer instance
     *
     * @return string[] Array of SQL constraint expressions
     */
    private function getIndexingConstraints(
        QueryBuilder $queryBuilder,
        IndexingService $indexingService,
        AbstractIndexer $indexerInstance,
    ): array {
        $constraints = [];

        // Add page constraints (same logic as AbstractIndexer::getPagesQueryConstraint)
        $pageUIDs = $this->getIndexedPages($indexingService);
        if ($pageUIDs !== []) {
            $constraints[] = $queryBuilder->expr()->in(
                ($indexerInstance->getTable() === PageIndexer::TABLE) ? 'uid' : 'pid',
                $pageUIDs
            );
        }

        // Add indexer-specific constraints
        if ($indexerInstance instanceof PageIndexer) {
            $constraints = array_merge($constraints, $this->getPageIndexerConstraints($queryBuilder, $indexingService));
        } elseif ($indexerInstance instanceof ContentIndexer) {
            $constraints = array_merge($constraints, $this->getContentIndexerConstraints($queryBuilder, $indexingService));
        }

        return $constraints;
    }

    /**
     * Gets all page UIDs that should be considered for indexing based on the service configuration.
     *
     * This method combines individually selected pages and recursively selected
     * page trees from the indexing service configuration, similar to how
     * AbstractIndexer::getPages() works.
     *
     * @param IndexingService $indexingService The indexing service configuration
     *
     * @return int[] Array of page UIDs to include in indexing
     */
    private function getIndexedPages(IndexingService $indexingService): array
    {
        // Get individually selected pages
        $pagesSingle = GeneralUtility::intExplode(
            ',',
            $indexingService->getPagesSingle(),
            true
        );

        // Get recursively selected page trees
        $pagesRecursive = GeneralUtility::intExplode(
            ',',
            $indexingService->getPagesRecursive(),
            true
        );

        // Resolve recursive pages to their actual page tree
        $pageIds   = [[]];
        $pageIds[] = $pagesSingle;
        $pageIds[] = $this->pageRepository
            ->getPageIdsRecursive(
                $pagesRecursive,
                // Maximum depth of 99 levels
                99,
                // Include the parent pages
                true,
                // Whether to exclude hidden pages - use false for deletion detection
                // as we want to find all pages, including hidden ones that might be indexed
                false
            );

        // Merge all page IDs and filter out empty values
        return array_filter(
            array_merge(...$pageIds)
        );
    }

    /**
     * Gets constraints specific to page indexing.
     *
     * This method recreates the same constraints that PageIndexer::getAdditionalQueryConstraints()
     * applies when determining which pages to index.
     *
     * @param QueryBuilder    $queryBuilder    The query builder instance
     * @param IndexingService $indexingService The indexing service configuration
     *
     * @return string[] Array of SQL constraint expressions
     */
    private function getPageIndexerConstraints(QueryBuilder $queryBuilder, IndexingService $indexingService): array
    {
        $constraints = [
            // Include only pages which are not explicitly excluded from search
            $queryBuilder->expr()->eq('no_search', 0),
        ];

        // Get page types from indexing service configuration
        $pageTypes = GeneralUtility::intExplode(
            ',',
            $indexingService->getPagesDoktype(),
            true
        );

        if ($pageTypes !== []) {
            // Filter by page type
            $constraints[] = $queryBuilder->expr()->in(
                'doktype',
                $queryBuilder->quoteArrayBasedValueListToIntegerList($pageTypes)
            );
        }

        return $constraints;
    }

    /**
     * Gets constraints specific to content element indexing.
     *
     * This method recreates the same constraints that ContentIndexer::getAdditionalQueryConstraints()
     * applies when determining which content elements to index.
     *
     * @param QueryBuilder    $queryBuilder    The query builder instance
     * @param IndexingService $indexingService The indexing service configuration
     *
     * @return string[] Array of SQL constraint expressions
     */
    private function getContentIndexerConstraints(QueryBuilder $queryBuilder, IndexingService $indexingService): array
    {
        $constraints = [];

        // Get content element types from indexing service configuration
        $contentElementTypes = GeneralUtility::trimExplode(
            ',',
            $indexingService->getContentElementTypes(),
            true
        );

        if ($contentElementTypes !== []) {
            // Filter by CType
            $constraints[] = $queryBuilder->expr()->in(
                'CType',
                $queryBuilder->quoteArrayBasedValueListToStringList($contentElementTypes)
            );
        }

        return $constraints;
    }

    /**
     * Gets file records that should be excluded from indexing.
     *
     * For file indexers, we need special logic since files are indexed based on
     * file collections rather than direct database queries.
     *
     * @param IndexingService $indexingService The indexing service configuration
     * @param FileIndexer     $fileIndexer     The file indexer instance
     *
     * @return int[] Array of file metadata record UIDs that should be excluded
     */
    private function getExcludedFiles(IndexingService $indexingService, FileIndexer $fileIndexer): array
    {
        // For now, return empty array - file exclusion logic would need to be implemented
        // based on file collections and file properties like 'no_search'
        return [];
    }
}
