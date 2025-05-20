<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\DataHandling;

use Doctrine\DBAL\Exception;
use Generator;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Core handler for database record operations in the search indexing process.
 *
 * This class manages the lifecycle of records in the search indexing system:
 * - Adding records to the indexing queue
 * - Removing records from the queue
 * - Updating records in the queue when content changes
 * - Deleting records from search indices
 * - Managing relationships between records (e.g., content elements on pages)
 *
 * It serves as a central coordination point between the TYPO3 DataHandler hooks,
 * the indexing queue, and the search engine services, ensuring that database
 * records are properly synchronized with search indices.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordHandler
{
    /**
     * Factory for creating search engine service instances.
     *
     * This factory is used to create instances of search engine services based on
     * their type (e.g., Algolia). These services provide the actual implementation
     * for communicating with the search engine APIs.
     *
     * @var SearchEngineFactory
     */
    private readonly SearchEngineFactory $searchEngineFactory;

    /**
     * Factory for creating indexer instances.
     *
     * This factory is used to create instances of indexers based on their type
     * (e.g., page indexer, content indexer, file indexer). These indexers handle
     * the actual process of adding items to the indexing queue.
     *
     * @var IndexerFactory
     */
    private readonly IndexerFactory $indexerFactory;

    /**
     * Repository for page-related operations.
     *
     * This repository provides methods for retrieving page information,
     * particularly for determining root page IDs and page hierarchies
     * that are essential for proper indexing context.
     *
     * @var PageRepository
     */
    private readonly PageRepository $pageRepository;

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
     * Repository for content element operations.
     *
     * This repository provides methods for retrieving content elements,
     * particularly for finding all content elements on a specific page
     * that need to be indexed or removed from the index.
     *
     * @var ContentRepository
     */
    private readonly ContentRepository $contentRepository;

    /**
     * Initializes the record handler with required dependencies.
     *
     * This constructor injects all the services and repositories needed for the
     * record handler to manage database records in the search indexing system.
     * It follows TYPO3's dependency injection pattern to ensure the handler
     * has access to all required functionality.
     *
     * @param SearchEngineFactory       $searchEngineFactory       Factory for creating search engine service instances
     * @param IndexerFactory            $indexerFactory            Factory for creating indexer instances
     * @param PageRepository            $pageRepository            Repository for page-related operations
     * @param IndexingServiceRepository $indexingServiceRepository Repository for accessing indexing service configurations
     * @param ContentRepository         $contentRepository         Repository for content element operations
     */
    public function __construct(
        SearchEngineFactory $searchEngineFactory,
        IndexerFactory $indexerFactory,
        PageRepository $pageRepository,
        IndexingServiceRepository $indexingServiceRepository,
        ContentRepository $contentRepository,
    ) {
        $this->searchEngineFactory       = $searchEngineFactory;
        $this->indexerFactory            = $indexerFactory;
        $this->pageRepository            = $pageRepository;
        $this->indexingServiceRepository = $indexingServiceRepository;
        $this->contentRepository         = $contentRepository;
    }

    /**
     * Creates a generator that yields indexing service and indexer instance pairs.
     *
     * This method finds all indexing services configured for a specific table name
     * and creates the appropriate indexer instances for each service. It returns
     * a generator that yields pairs of indexing service and indexer instance,
     * allowing for efficient iteration over all applicable indexers without
     * loading them all into memory at once.
     *
     * The method also filters indexers based on the root page ID to ensure that
     * only indexers relevant to the current page tree are included.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The name of the database table to find indexing services for
     *
     * @return Generator A generator yielding pairs of IndexingService => IndexerInterface
     */
    public function createIndexerGenerator(int $rootPageId, string $tableName): Generator
    {
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName($tableName);

        /** @var IndexingService $indexingService */
        foreach ($indexingServices as $indexingService) {
            $indexerInstance = $this->getResponsibleRecordIndexer(
                $indexingService,
                $rootPageId
            );

            if (!($indexerInstance instanceof IndexerInterface)) {
                continue;
            }

            yield $indexingService => $indexerInstance;
        }
    }

    /**
     * Updates a record in the indexing queue by removing and re-adding it.
     *
     * This method is called when a record has been modified and needs to be
     * re-indexed. It:
     * 1. Finds all applicable indexing services for the record's table
     * 2. For each service, creates the appropriate indexer instance
     * 3. Removes the record from the queue (dequeueOne)
     * 4. Immediately adds it back to the queue (enqueueOne)
     *
     * This process ensures that the record will be re-indexed with its latest
     * content during the next indexing run.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The name of the database table containing the record
     * @param int    $recordUid  The unique identifier of the record to update in the queue
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue operations
     */
    public function updateRecordInQueue(int $rootPageId, string $tableName, int $recordUid): void
    {
        $indexerInstanceGenerator = $this->createIndexerGenerator($rootPageId, $tableName);

        foreach ($indexerInstanceGenerator as $indexerInstance) {
            // Remove possible entry of the record from the queue item table
            // and add it again to update index
            $indexerInstance
                ->dequeueOne($recordUid)
                ->enqueueOne($recordUid);
        }
    }

    /**
     * Updates a page in the indexing queue when one of its content elements changes.
     *
     * This method is called when a content element has been modified, and the
     * containing page needs to be re-indexed as a result. It only takes action
     * if the page indexer is configured to include content elements in the page
     * index record (isIncludeContentElements() returns true).
     *
     * The method:
     * 1. Finds all page indexers applicable to the page's root page
     * 2. Checks if each indexer is configured to include content elements
     * 3. For those that do, removes the page from the queue and re-adds it
     *
     * This ensures that when content elements change, the page index is updated
     * to reflect those changes during the next indexing run.
     *
     * @param int $rootPageId The root page UID to filter indexing services by page tree
     * @param int $pageId     The unique identifier of the page containing the modified content element
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue operations
     */
    public function processPageOfContentElement(int $rootPageId, int $pageId): void
    {
        $indexerInstanceGenerator = $this->createIndexerGenerator(
            $rootPageId,
            PageIndexer::TABLE
        );

        foreach ($indexerInstanceGenerator as $indexerInstance) {
            if (!($indexerInstance instanceof PageIndexer)) {
                continue;
            }

            // If the page indexer is configured to include content items in the page index record,
            // we add an additional entry to the queue for the content element's page.
            if (!$indexerInstance->isIncludeContentElements()) {
                continue;
            }

            // Remove possible entry of the record from the queue item table
            // and add it again to update index
            $indexerInstance
                ->dequeueOne($pageId)
                ->enqueueOne($pageId);
        }
    }

    /**
     * Manages the indexing status of all content elements on a specific page.
     *
     * This method is typically called when a page is being processed (e.g., moved,
     * deleted, or hidden) and its content elements need to be updated accordingly
     * in the indexing queue and search indices.
     *
     * The method:
     * 1. Finds all content element indexer services
     * 2. Retrieves all content elements on the specified page
     * 3. Based on the $removePageContentElements parameter:
     *    - If true: Removes all content elements from both the queue and search indices
     *    - If false: Adds all content elements to the queue for indexing
     *
     * This ensures that content elements are properly synchronized with their
     * parent page's status in the search system.
     *
     * @param int  $pageId                    The unique identifier of the page containing the content elements
     * @param bool $removePageContentElements Whether to remove elements from queue and index (true) or add them to the queue (false)
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue or index operations
     */
    public function processContentElementsOfPage(int $pageId, bool $removePageContentElements): void
    {
        // Get all content element indexer services
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName(ContentIndexer::TABLE);

        /** @var IndexingService $indexingService */
        foreach ($indexingServices as $indexingService) {
            $indexerInstance = $this->indexerFactory
                ->makeInstanceByType($indexingService->getType())
                ?->withIndexingService($indexingService);

            if (!($indexerInstance instanceof ContentIndexer)) {
                continue;
            }

            // Get all the UIDs of all content elements of this page
            $rowsWithUid = $this->contentRepository
                ->findAllByPid(
                    $pageId,
                    [
                        'uid',
                    ],
                );

            $contentElementUids = array_column($rowsWithUid, 'uid');

            if ($contentElementUids === []) {
                continue;
            }

            if ($removePageContentElements) {
                $this->deleteRecords(
                    $indexingService,
                    $indexerInstance,
                    $indexerInstance->getTable(),
                    $contentElementUids,
                    true
                );
            } else {
                $indexerInstance
                    ->enqueueMultiple($contentElementUids);
            }
        }
    }

    /**
     * Determines the root page ID for any record in the TYPO3 page tree.
     *
     * This method finds the root page (the top-level page in a site) that contains
     * a given record. For page records, it directly determines the root page.
     * For other record types, it first finds the page containing the record,
     * then determines the root page for that page.
     *
     * The root page ID is essential for:
     * - Determining which indexing services apply to a record
     * - Ensuring records are indexed in the correct site context
     * - Maintaining proper page tree hierarchies in search results
     *
     * @param string $tableName The database table name of the record
     * @param int    $recordUid The unique identifier of the record
     *
     * @return int The root page ID for the record, or 0 if no valid root page is found
     */
    public function getRecordRootPageId(string $tableName, int $recordUid): int
    {
        $recordPageId = $recordUid;

        if ($tableName !== 'pages') {
            $recordPageId = $this->getRecordPageId($tableName, $recordUid);
        }

        return $this->pageRepository->getRootPageId($recordPageId);
    }

    /**
     * Retrieves the parent page ID for any non-page record in TYPO3.
     *
     * This helper method finds the page that contains a given record by looking up
     * the 'pid' field of the record. In TYPO3, most records have a 'pid' field that
     * indicates which page they belong to.
     *
     * The method uses TYPO3's BackendUtility::getRecord() to efficiently retrieve
     * just the 'pid' field without loading the entire record.
     *
     * @param string $tableName The database table name of the record
     * @param int    $recordUid The unique identifier of the record
     *
     * @return int The parent page ID for the record, or 0 if the record doesn't exist or has no valid pid
     */
    private function getRecordPageId(string $tableName, int $recordUid): int
    {
        $record = BackendUtility::getRecord($tableName, $recordUid, 'pid');

        if ($record === null) {
            return 0;
        }

        return (int) ($record['pid'] ?? 0);
    }

    /**
     * Finds the appropriate indexer instance for a given indexing service and page tree.
     *
     * This method determines if an indexing service is applicable to a specific
     * page tree (identified by its root page ID) and creates the corresponding
     * indexer instance if it is. The method:
     *
     * 1. For non-file records, checks if the indexing service belongs to the same
     *    page tree as the specified root page ID
     * 2. For file metadata records (sys_file_metadata), skips the page tree check
     *    since files can be used across multiple page trees
     * 3. Creates and returns the appropriate indexer instance if the service is applicable
     *
     * This filtering ensures that records are only indexed by services that are
     * configured for their specific site/page tree context.
     *
     * @param IndexingService $indexingService The indexing service configuration to check
     * @param int             $rootPageId      The root page ID to check against
     *
     * @return IndexerInterface|null The configured indexer instance or null if not applicable
     */
    private function getResponsibleRecordIndexer(
        IndexingService $indexingService,
        int $rootPageId,
    ): ?IndexerInterface {
        if ($indexingService->getType() !== 'sys_file_metadata') {
            // Determine the root page ID for the indexing service
            $indexingServiceRootPageId = $this->getRecordRootPageId(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                (int) $indexingService->getUid()
            );

            // Ignore this indexing service because the root page IDs do not match,
            // meaning the indexing service is not part of the same page tree.
            if ($rootPageId !== $indexingServiceRootPageId) {
                return null;
            }
        }

        return $this->indexerFactory
            ->makeInstanceByType($indexingService->getType())
            ?->withIndexingService($indexingService);
    }

    /**
     * Removes a single record from the indexing queue and optionally from the search index.
     *
     * This method is called when a record needs to be removed from the search system,
     * typically because it has been deleted, hidden, or otherwise made unavailable.
     * It performs two separate but related operations:
     *
     * 1. Always removes the record from the indexing queue to prevent it from being
     *    indexed in future indexing runs
     * 2. Optionally removes the record from the actual search engine index based on
     *    the $isRemoveFromIndex parameter
     *
     * This two-step approach allows for flexible handling of different scenarios,
     * such as temporarily removing items from the queue without affecting the
     * search index, or completely purging items from both systems.
     *
     * @param IndexingService  $indexingService   The indexing service configuration to use
     * @param IndexerInterface $indexerInstance   The indexer instance for the record's type
     * @param string           $tableName         The database table name of the record
     * @param int              $recordUid         The unique identifier of the record
     * @param bool             $isRemoveFromIndex Whether to also remove the record from the search engine index
     *
     * @return void
     */
    public function deleteRecord(
        IndexingService $indexingService,
        IndexerInterface $indexerInstance,
        string $tableName,
        int $recordUid,
        bool $isRemoveFromIndex,
    ): void {
        // Remove possible entry of the record from the queue item table
        $indexerInstance
            ->dequeueOne($recordUid);

        // Remove record from index
        if ($isRemoveFromIndex) {
            $this->deleteRecordFromSearchEngine(
                $indexingService->getSearchEngine(),
                $tableName,
                $recordUid
            );
        }
    }

    /**
     * Removes multiple records from the indexing queue and optionally from the search index.
     *
     * This method is the batch version of deleteRecord(), handling multiple records
     * at once for better performance. It's typically called when a group of related
     * records needs to be removed from the search system, such as when:
     * - A page with multiple content elements is deleted
     * - A category with multiple items is hidden
     * - A bulk operation affects multiple records
     *
     * Like deleteRecord(), it performs two operations:
     * 1. Always removes the records from the indexing queue
     * 2. Optionally removes the records from the search engine index
     *
     * Processing multiple records in a single operation is more efficient than
     * calling deleteRecord() repeatedly, especially for the queue operations.
     *
     * @param IndexingService  $indexingService   The indexing service configuration to use
     * @param IndexerInterface $indexerInstance   The indexer instance for the records' type
     * @param string           $tableName         The database table name of the records
     * @param int[]            $recordUids        Array of unique identifiers for the records to delete
     * @param bool             $isRemoveFromIndex Whether to also remove the records from the search engine index
     *
     * @return void
     */
    public function deleteRecords(
        IndexingService $indexingService,
        IndexerInterface $indexerInstance,
        string $tableName,
        array $recordUids,
        bool $isRemoveFromIndex,
    ): void {
        // Remove possible entry of the record from the queue item table
        $indexerInstance
            ->dequeueMultiple($recordUids);

        // Remove record from index
        if ($isRemoveFromIndex) {
            $this->deleteRecordsFromSearchEngine(
                $indexingService->getSearchEngine(),
                $tableName,
                $recordUids
            );
        }
    }

    /**
     * Removes a single record from the search engine index.
     *
     * This helper method handles the actual communication with the search engine
     * to remove a record from the search index. It:
     *
     * 1. Creates the appropriate search engine service instance based on the
     *    search engine configuration (e.g., Algolia)
     * 2. Configures the service with the correct index name
     * 3. Calls the deleteFromIndex method to remove the record from the index
     *
     * The method silently returns if the search engine service cannot be created,
     * which might happen if the search engine configuration is invalid or if
     * the search engine type is not supported.
     *
     * @param SearchEngine $searchEngine The search engine configuration to use
     * @param string       $tableName    The database table name of the record
     * @param int          $recordUid    The unique identifier of the record to delete
     *
     * @return void
     */
    private function deleteRecordFromSearchEngine(
        SearchEngine $searchEngine,
        string $tableName,
        int $recordUid,
    ): void {
        // Get underlying search engine instance
        $searchEngineService = $this->searchEngineFactory
            ->makeInstanceBySearchEngineModel($searchEngine);

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return;
        }

        $searchEngineService
            ->withIndexName($searchEngine->getIndexName())
            ->deleteFromIndex(
                $tableName,
                $recordUid
            );
    }

    /**
     * Removes multiple records from the search engine index.
     *
     * This helper method handles the actual communication with the search engine
     * to remove multiple records from the search index. Unlike deleteRecordFromSearchEngine,
     * this method processes an array of record UIDs, but it does so by iterating through
     * them and calling deleteFromIndex individually for each record.
     *
     * The method:
     * 1. Creates the appropriate search engine service instance
     * 2. Configures the service with the correct index name
     * 3. Iterates through each record UID and calls deleteFromIndex for each one
     *
     * This approach ensures that each record is properly removed from the index,
     * even if some records might fail (the operation continues with the next record).
     *
     * @param SearchEngine $searchEngine The search engine configuration to use
     * @param string       $tableName    The database table name of the records
     * @param int[]        $recordUids   Array of unique identifiers for the records to delete
     *
     * @return void
     */
    private function deleteRecordsFromSearchEngine(
        SearchEngine $searchEngine,
        string $tableName,
        array $recordUids,
    ): void {
        // Get underlying search engine instance
        $searchEngineService = $this->searchEngineFactory
            ->makeInstanceBySearchEngineModel($searchEngine);

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return;
        }

        foreach ($recordUids as $recordUid) {
            $searchEngineService
                ->withIndexName($searchEngine->getIndexName())
                ->deleteFromIndex(
                    $tableName,
                    $recordUid
                );
        }
    }
}
