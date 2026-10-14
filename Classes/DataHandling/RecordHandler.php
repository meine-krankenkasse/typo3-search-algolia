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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_chunk;
use function array_column;
use function array_diff;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;

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
readonly class RecordHandler implements RecordHandlerInterface
{
    /**
     * The number of records that one removal request to the search engine holds.
     */
    private const int REMOVAL_BLOCK_SIZE = 1000;

    /**
     * The number of removal blocks in a row that may fail before the rest is not sent.
     */
    private const int MAX_CONSECUTIVE_REMOVAL_FAILURES = 2;

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
     * @param LoggerInterface|null      $logger                    Logger for failures that must not abort the editor's action
     */
    public function __construct(
        private SearchEngineFactory $searchEngineFactory,
        private IndexerFactory $indexerFactory,
        private PageRepository $pageRepository,
        private IndexingServiceRepository $indexingServiceRepository,
        private ContentRepository $contentRepository,
        private ?LoggerInterface $logger = null,
    ) {
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
    #[Override]
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
     * Updates records of one table in the indexing queue in one pass, and removes the
     * documents that no indexing service accepts any more.
     *
     * This method is called when records have been modified or moved and need to be
     * indexed again. It:
     *
     * 1. Resolves the indexing services consulted for the records once.
     * 2. Lets each service dequeue the records and queue those it accepts, with
     *    one lookup for a block of the list and not one per record.
     * 3. Removes the document of a record from a search index when no consulted
     *    service that writes to that index accepts the record, and when no service
     *    that was not consulted writes to it. The removal is sent in blocks.
     *
     * With the default document ID, a document is addressed by table and record
     * only, so indexing services that share one search index share the document as
     * well. The consulted services are the ones of the site of the root page, the
     * ones of the former site for a moved record and, for files, every file indexing
     * service. Other indexes are decided separately, and a service of another site
     * is not consulted, so the document is kept when such a service writes to the
     * same index, and a warning is logged.
     *
     * A rejected file is only treated as no longer accepted when the list of allowed
     * file extensions is configured. Without that list every file is rejected, so
     * nothing is removed and a warning is logged instead. A failure while removing the
     * documents is logged as an error and does not abort the action of the editor.
     * The queue operations are not guarded. The search engines of the services are
     * only looked at when at least one service rejects a record.
     *
     * @param int      $rootPageId              The root page UID to filter indexing services by page tree
     * @param string   $tableName               The name of the database table containing the records
     * @param int[]    $recordUids              The unique identifiers of the records to update in the queue
     * @param bool     $removeRejectedDocuments Whether to remove the documents of records that no indexer accepts
     * @param int|null $previousRootPageId      The root page UID of the former site of moved records
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue operations
     */
    #[Override]
    public function updateRecordsInQueue(
        int $rootPageId,
        string $tableName,
        array $recordUids,
        bool $removeRejectedDocuments = true,
        ?int $previousRootPageId = null,
    ): void {
        $recordUids = array_values(array_unique($recordUids));

        if ($recordUids === []) {
            return;
        }

        /** @var list<array{IndexingService, IndexerInterface, array<int, true>}> $verdicts */
        $verdicts = [];

        /** @var list<int> $consultedServiceUids */
        $consultedServiceUids = [];

        $hasRejection = false;

        $consultedIndexers = $this->createConsultedIndexerGenerator(
            $rootPageId,
            $tableName,
            $previousRootPageId,
        );

        foreach ($consultedIndexers as $indexingService => $indexerInstance) {
            $consultedServiceUids[] = (int) $indexingService->getUid();

            // The indexer rejects a record it no longer accepts, for example a page
            // that moved out of the selected page tree
            $indexerInstance->dequeueMultiple($recordUids);

            $accepted = array_fill_keys($indexerInstance->enqueueEligible($recordUids), true);

            $verdicts[]   = [$indexingService, $indexerInstance, $accepted];
            $hasRejection = $hasRejection || (count($accepted) < count($recordUids));
        }

        // The search engines are only resolved when a document may have to be removed
        if (
            !$removeRejectedDocuments
            || !$hasRejection
        ) {
            return;
        }

        /** @var array<int, string|null> $indexKeys */
        $indexKeys = [];

        /** @var array<string, array<int, true>> $acceptedByIndex The records some consulted service of the index accepts */
        $acceptedByIndex = [];

        /** @var array<string, array{IndexingService, IndexerInterface, array<int, int>}> $rejectedByIndex */
        $rejectedByIndex = [];

        foreach ($verdicts as [$indexingService, $indexerInstance, $accepted]) {
            // A service whose search engine cannot be resolved takes no part
            // in the decision, which concerns the documents of an index
            $indexKey = $this->resolveIndexKey($indexingService, $indexKeys);

            if ($indexKey === null) {
                continue;
            }

            $acceptedByIndex[$indexKey] = ($acceptedByIndex[$indexKey] ?? []) + $accepted;

            $rejectedUids = array_diff($recordUids, array_keys($accepted));

            if ($rejectedUids === []) {
                continue;
            }

            if (!isset($rejectedByIndex[$indexKey])) {
                $rejectedByIndex[$indexKey] = [$indexingService, $indexerInstance, []];
            }

            foreach ($rejectedUids as $rejectedUid) {
                $rejectedByIndex[$indexKey][2][$rejectedUid] = $rejectedUid;
            }
        }

        foreach ($rejectedByIndex as $indexKey => [$indexingService, $indexerInstance, $rejectedUids]) {
            $removalUids = array_values(
                array_diff($rejectedUids, array_keys($acceptedByIndex[$indexKey] ?? []))
            );

            if ($removalUids === []) {
                continue;
            }

            try {
                // Without the list of allowed file extensions every file is
                // rejected, which says nothing about the file
                if (
                    ($indexerInstance instanceof FileIndexer)
                    && !$indexerInstance->hasAllowedFileExtensions()
                ) {
                    $this->logger?->warning(
                        'Documents of rejected files were kept, because the allowed file extensions are not configured',
                        [
                            'tableName' => $tableName,
                            ...$this->describeRecordUids($removalUids),
                        ]
                    );

                    continue;
                }

                if (
                    $this->hasUnconsultedWriter(
                        $tableName,
                        $indexKey,
                        $consultedServiceUids,
                        $indexKeys,
                    )
                ) {
                    $this->logger?->warning(
                        'Documents of rejected records were kept, because an indexing service that was not consulted writes to the same index',
                        [
                            'tableName' => $tableName,
                            ...$this->describeRecordUids($removalUids),
                        ]
                    );

                    continue;
                }

                $this->deleteRecords(
                    $indexingService,
                    $indexerInstance,
                    $tableName,
                    $removalUids
                );
            } catch (Throwable $exception) {
                // The documents of records that are no longer accepted stay in the index
                $this->logger?->error(
                    'Documents of records the indexer no longer accepts could not be removed',
                    [
                        'tableName' => $tableName,
                        ...$this->describeRecordUids($removalUids),
                        'exception' => $exception,
                    ]
                );
            }
        }
    }

    /**
     * Creates a generator over the indexing services that are consulted for a record.
     *
     * These are the services of the site of the root page and, for a record that
     * moved to another site, the ones of its former site as well.
     *
     * @param int      $rootPageId         The root page UID of the site the record lives on
     * @param string   $tableName          The name of the database table to find indexing services for
     * @param int|null $previousRootPageId The root page UID of the former site, or NULL
     *
     * @return Generator A generator yielding pairs of IndexingService => IndexerInterface
     */
    private function createConsultedIndexerGenerator(
        int $rootPageId,
        string $tableName,
        ?int $previousRootPageId,
    ): Generator {
        yield from $this->createIndexerGenerator($rootPageId, $tableName);

        if (
            ($previousRootPageId !== null)
            && ($previousRootPageId > 0)
            && ($previousRootPageId !== $rootPageId)
        ) {
            yield from $this->createIndexerGenerator($previousRootPageId, $tableName);
        }
    }

    /**
     * Returns the key that identifies the search index an indexing service writes to.
     *
     * A search engine record that is hidden or deleted cannot be resolved. That
     * is logged, and the service then takes no part in the decision about the
     * documents of an index.
     *
     * @param IndexingService         $indexingService The indexing service to resolve the search index for
     * @param array<int, string|null> &$indexKeys      The keys resolved so far by service UID, so that a failure is logged once per call
     *
     * @return string|null The key of engine and index name, or NULL if the search engine cannot be resolved
     */
    private function resolveIndexKey(
        IndexingService $indexingService,
        array &$indexKeys,
    ): ?string {
        $serviceUid = (int) $indexingService->getUid();

        if (array_key_exists($serviceUid, $indexKeys)) {
            return $indexKeys[$serviceUid];
        }

        try {
            $searchEngine = $indexingService->getSearchEngine();

            return $indexKeys[$serviceUid] = $searchEngine->getEngine() . '/' . $searchEngine->getIndexName();
        } catch (Throwable $exception) {
            $this->logger?->warning(
                'Search engine of an indexing service could not be resolved',
                [
                    'indexingService' => $serviceUid,
                    'exception'       => $exception,
                ]
            );

            return $indexKeys[$serviceUid] = null;
        }
    }

    /**
     * Tells whether an indexing service that was not consulted for the record
     * writes to the given search index.
     *
     * Only the services of the record's own site are consulted. A service of
     * another site that writes to the same index could still accept the record
     * and, with the default document ID, owns the same document, so the document
     * must not be removed then.
     *
     * @param string                  $tableName            The name of the database table of the indexing services
     * @param string                  $indexKey             The key of the search index
     * @param int[]                   $consultedServiceUids The UIDs of the services that were consulted for the record
     * @param array<int, string|null> &$indexKeys           The keys resolved so far by service UID
     *
     * @return bool TRUE if a service that was not consulted writes to the search index
     */
    private function hasUnconsultedWriter(
        string $tableName,
        string $indexKey,
        array $consultedServiceUids,
        array &$indexKeys,
    ): bool {
        foreach ($this->indexingServiceRepository->findAllByTableName($tableName) as $indexingService) {
            if (in_array((int) $indexingService->getUid(), $consultedServiceUids, true)) {
                continue;
            }

            if ($this->resolveIndexKey($indexingService, $indexKeys) === $indexKey) {
                return true;
            }
        }

        return false;
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
    #[Override]
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
     * @param int        $pageId                    The unique identifier of the page containing the content elements
     * @param bool       $removePageContentElements Whether to remove elements from queue and index (true) or add them to the queue (false)
     * @param int[]|null $contentElementUids        The UIDs of the content elements, or NULL to look them up on the page
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue or index operations
     */
    #[Override]
    public function processContentElementsOfPage(
        int $pageId,
        bool $removePageContentElements,
        ?array $contentElementUids = null,
    ): void {
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

            // Get all the UIDs of all content elements of this page, unless the caller knows them
            $uids = $contentElementUids
                ?? array_column(
                    $this->contentRepository
                        ->findAllByPid(
                            $pageId,
                            [
                                'uid',
                            ],
                        ),
                    'uid'
                );

            if ($uids === []) {
                continue;
            }

            if ($removePageContentElements) {
                $this->deleteRecords(
                    $indexingService,
                    $indexerInstance,
                    $indexerInstance->getTable(),
                    $uids
                );
            } else {
                // Entries that are still pending would collide with the new ones, so
                // they are taken out of the queue first
                $indexerInstance
                    ->dequeueMultiple($uids);

                $indexerInstance
                    ->enqueueEligible($uids);
            }
        }
    }

    /**
     * Retrieves the root page ID for a given record.
     *
     * This method determines the root page ID associated with a record. If the
     * table name is not 'pages', it calculates the page ID via the associated
     * record. Otherwise, the provided record UID is used. The root page ID
     * is then resolved through the page repository.
     *
     * @param array<string, int|string|null> $pageRecord The record data used for determining the page ID
     * @param string                         $tableName  The name of the table that the record belongs to
     * @param int                            $recordUid  The unique identifier of the record
     *
     * @return int The resolved root page ID
     */
    #[Override]
    public function getRecordRootPageId(array $pageRecord, string $tableName, int $recordUid): int
    {
        $recordPageId = $recordUid;

        if ($tableName !== 'pages') {
            $recordPageId = $this->getRecordPageId($pageRecord);
        }

        return $this->pageRepository->getRootPageId($recordPageId);
    }

    /**
     * Retrieves the page ID from the given page record.
     *
     * This method extracts the 'pid' field from the provided page record array
     * and returns it as an integer. If the 'pid' field is not set, it defaults to 0.
     *
     * @param array<string, int|string|null> $pageRecord Associative array representing a page record, typically containing a 'pid' field
     *
     * @return int The page ID extracted from the record, or 0 if 'pid' is not set
     */
    private function getRecordPageId(array $pageRecord): int
    {
        return (int) ($pageRecord['pid'] ?? 0);
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
            $pageRecord = $this->pageRepository
                ->getPageRecord(
                    'tx_typo3searchalgolia_domain_model_indexingservice',
                    (int) $indexingService->getUid()
                );

            // Determine the root page ID for the indexing service
            $indexingServiceRootPageId = $this->getRecordRootPageId(
                $pageRecord,
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
     * Removes several records of one table from the indexing queue and from the
     * search index of every indexing service of the page tree of the given root page.
     *
     * The indexing services are resolved once for all records, not once per record.
     * A search engine that fails is logged and does not abort the action of the editor.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The database table name of the records
     * @param int[]  $recordUids The unique identifiers of the records
     *
     * @return void
     */
    #[Override]
    public function removeRecords(
        int $rootPageId,
        string $tableName,
        array $recordUids,
    ): void {
        if ($recordUids === []) {
            return;
        }

        $this->removeRecordsFromTargets(
            $this->resolveIndexerTargets($rootPageId, $tableName),
            $tableName,
            $recordUids
        );
    }

    /**
     * Resolves the indexing services of the page tree of the given root page together
     * with their indexers, as the targets of a later removal.
     *
     * A removal that has to happen after a deletion resolves its targets before the
     * deletion, because the indexing services may be stored in the deleted tree and are
     * deleted with it.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The database table name of the records
     *
     * @return array<int, array{IndexingService, IndexerInterface}> The indexing services with their indexers
     */
    #[Override]
    public function resolveIndexerTargets(
        int $rootPageId,
        string $tableName,
    ): array {
        $targets = [];

        foreach ($this->createIndexerGenerator($rootPageId, $tableName) as $indexingService => $indexerInstance) {
            $targets[] = [$indexingService, $indexerInstance];
        }

        return $targets;
    }

    /**
     * Removes several records of one table from the indexing queue and from the search
     * index of the given targets.
     *
     * @param array<int, array{IndexingService, IndexerInterface}> $targets    The indexing services with their indexers, see resolveIndexerTargets()
     * @param string                                               $tableName  The database table name of the records
     * @param int[]                                                $recordUids The unique identifiers of the records
     *
     * @return void
     */
    #[Override]
    public function removeRecordsFromTargets(
        array $targets,
        string $tableName,
        array $recordUids,
    ): void {
        foreach ($targets as [$indexingService, $indexerInstance]) {
            $this->deleteRecords(
                $indexingService,
                $indexerInstance,
                $tableName,
                $recordUids
            );
        }
    }

    /**
     * Removes multiple records from the indexing queue and from the search index.
     *
     * It's called when one record or a group of related records needs to be removed
     * from the search system, such as when:
     * - A page with multiple content elements is deleted
     * - A category with multiple items is hidden
     * - A bulk operation affects multiple records
     *
     * It performs these operations:
     * 1. Removes the records from the indexing queue
     * 2. Removes the records from the search engine index
     *
     * A search engine that fails is logged as an error and does not abort the
     * action of the editor. The records are dequeued before the engine is asked.
     *
     * @param IndexingService  $indexingService The indexing service configuration to use
     * @param IndexerInterface $indexerInstance The indexer instance for the records' type
     * @param string           $tableName       The database table name of the records
     * @param int[]            $recordUids      Array of unique identifiers for the records to delete
     *
     * @return void
     */
    private function deleteRecords(
        IndexingService $indexingService,
        IndexerInterface $indexerInstance,
        string $tableName,
        array $recordUids,
    ): void {
        // An empty list would mean every record of the table for the queue
        if ($recordUids === []) {
            return;
        }

        // Remove possible entry of the record from the queue item table. A failure is
        // logged, and the documents are still removed from the index.
        try {
            $indexerInstance
                ->dequeueMultiple($recordUids);
        } catch (Throwable $exception) {
            $this->logger?->error(
                'Queue items of records could not be removed',
                [
                    'tableName' => $tableName,
                    ...$this->describeRecordUids($recordUids),
                    'exception' => $exception,
                ]
            );
        }

        // Remove record from index
        try {
            $this->deleteRecordsFromSearchEngine(
                $indexingService->getSearchEngine(),
                $tableName,
                $recordUids
            );
        } catch (Throwable $exception) {
            // The records are dequeued already, and the action of the editor goes on
            $this->logger?->error(
                'Documents of records could not be removed from the index',
                [
                    'tableName' => $tableName,
                    ...$this->describeRecordUids($recordUids),
                    'exception' => $exception,
                ]
            );
        }
    }

    /**
     * Creates the search engine service for a search engine configuration.
     *
     * A configuration the factory cannot build a service for is logged, because
     * the documents of its index stay in place when the removal is skipped.
     *
     * @param SearchEngine $searchEngine The search engine configuration to use
     *
     * @return SearchEngineInterface|null The service, or NULL if none could be created
     */
    private function createSearchEngineService(SearchEngine $searchEngine): ?SearchEngineInterface
    {
        $searchEngineService = $this->searchEngineFactory
            ->makeInstanceBySearchEngineModel($searchEngine);

        if ($searchEngineService instanceof SearchEngineInterface) {
            return $searchEngineService;
        }

        $this->logger?->warning(
            'No search engine service could be created, so no document was removed from the index',
            [
                'engine'    => $searchEngine->getEngine(),
                'indexName' => $searchEngine->getIndexName(),
            ]
        );

        return null;
    }

    /**
     * Removes multiple records from the search engine index.
     *
     * This helper method handles the actual communication with the search engine
     * to remove multiple records from the search index. The records are sent in
     * blocks, and the search engine removes a block with as few requests as it can.
     *
     * The method:
     * 1. Creates the appropriate search engine service instance
     * 2. Configures the service with the correct index name
     * 3. Removes the records block by block
     *
     * A block that fails is logged as an error, and the blocks that follow are still
     * sent, until the blocks that fail in a row reach MAX_CONSECUTIVE_REMOVAL_FAILURES.
     * The records of a failed block and of the blocks that were not sent stay in the index.
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
        $searchEngineService = $this->createSearchEngineService($searchEngine);

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return;
        }

        $failures = 0;

        foreach (array_chunk($recordUids, self::REMOVAL_BLOCK_SIZE) as $index => $block) {
            try {
                $searchEngineService
                    ->withIndexName($searchEngine->getIndexName())
                    ->deleteManyFromIndex(
                        $tableName,
                        $block
                    );

                $failures = 0;
            } catch (Throwable $exception) {
                // The records are dequeued already, and the action of the editor goes on
                $this->logger?->error(
                    'Documents of records could not be removed from the index',
                    [
                        'tableName' => $tableName,
                        ...$this->describeRecordUids($block),
                        'exception' => $exception,
                    ]
                );

                // A search engine that cannot be reached would fail every block, and the
                // request of the editor would wait for each of them
                if (++$failures >= self::MAX_CONSECUTIVE_REMOVAL_FAILURES) {
                    $unsentRecordCount = count($recordUids) - (($index + 1) * self::REMOVAL_BLOCK_SIZE);

                    if ($unsentRecordCount > 0) {
                        $this->logger?->warning(
                            'Removal of documents was stopped after repeated failures',
                            [
                                'tableName'         => $tableName,
                                'unsentRecordCount' => $unsentRecordCount,
                            ]
                        );
                    }

                    return;
                }
            }
        }
    }

    /**
     * Describes a list of record UIDs for a log entry without logging all of them.
     *
     * @param int[] $recordUids The UIDs of the records
     *
     * @return array{recordCount: int, recordUids: int[]} The number of records and the first of them
     */
    private function describeRecordUids(array $recordUids): array
    {
        return [
            'recordCount' => count($recordUids),
            'recordUids'  => array_slice($recordUids, 0, 20),
        ];
    }
}
