<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Override;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract base class for all indexers.
 *
 * This abstract class provides the foundation for all indexers in the system.
 * It implements common functionality for:
 *
 * - Indexing records in search engines
 * - Managing queue items for indexing
 * - Handling record retrieval with proper constraints
 * - Supporting page-based filtering
 * - Managing indexing service configurations
 *
 * Concrete indexer implementations should extend this class and implement
 * the required abstract methods to provide specific indexing behavior
 * for different content types (pages, content elements, files, etc.).
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractIndexer implements IndexerInterface
{
    /**
     * Database connection pool for executing database queries.
     *
     * Used to create query builders for different database tables and
     * to retrieve records for indexing.
     *
     * @var ConnectionPool
     */
    protected ConnectionPool $connectionPool;

    /**
     * Site finder service for retrieving site configurations.
     *
     * Used to resolve site-specific information when indexing content.
     *
     * @var SiteFinder
     */
    protected SiteFinder $siteFinder;

    /**
     * Repository for page-related operations.
     *
     * Provides methods for retrieving page information and handling
     * page hierarchies for recursive indexing.
     *
     * @var PageRepository
     */
    protected PageRepository $pageRepository;

    /**
     * Factory for creating search engine instances.
     *
     * Used to create the appropriate search engine service based on
     * the configured search engine model.
     *
     * @var SearchEngineFactory
     */
    protected SearchEngineFactory $searchEngineFactory;

    /**
     * Repository for managing queue items.
     *
     * Handles the creation, retrieval, and deletion of queue items
     * for scheduled indexing operations.
     *
     * @var QueueItemRepository
     */
    protected QueueItemRepository $queueItemRepository;

    /**
     * Builder for creating document objects.
     *
     * Responsible for assembling document objects from records
     * that will be sent to the search engine for indexing.
     *
     * @var DocumentBuilder
     */
    private readonly DocumentBuilder $documentBuilder;

    /**
     * The currently used indexing service instance.
     *
     * This property stores the configuration for the current indexing operation,
     * including which search engine to use, which pages to index, and other
     * indexer-specific settings. It is set via the withIndexingService() method.
     *
     * @var IndexingService|null
     */
    protected ?IndexingService $indexingService = null;

    /**
     * Whether hidden pages should be excluded from indexing or not.
     *
     * When set to true, pages marked as hidden in the TYPO3 backend will be
     * excluded from indexing operations. This affects both direct page indexing
     * and the retrieval of page hierarchies for recursive indexing.
     * It is set via the withExcludeHiddenPages() method.
     *
     * @var bool
     */
    protected bool $excludeHiddenPages = false;

    /**
     * Constructor for the abstract indexer.
     *
     * Initializes the indexer with all required dependencies for database access,
     * site handling, page operations, search engine creation, queue management,
     * and document building.
     *
     * @param ConnectionPool      $connectionPool      Database connection pool for executing queries
     * @param SiteFinder          $siteFinder          Service for finding and handling TYPO3 sites
     * @param PageRepository      $pageRepository      Repository for page-related operations
     * @param SearchEngineFactory $searchEngineFactory Factory for creating search engine instances
     * @param QueueItemRepository $queueItemRepository Repository for managing indexing queue items
     * @param DocumentBuilder     $documentBuilder     Builder for creating document objects
     */
    public function __construct(
        ConnectionPool $connectionPool,
        SiteFinder $siteFinder,
        PageRepository $pageRepository,
        SearchEngineFactory $searchEngineFactory,
        QueueItemRepository $queueItemRepository,
        DocumentBuilder $documentBuilder,
    ) {
        $this->connectionPool      = $connectionPool;
        $this->siteFinder          = $siteFinder;
        $this->pageRepository      = $pageRepository;
        $this->searchEngineFactory = $searchEngineFactory;
        $this->queueItemRepository = $queueItemRepository;
        $this->documentBuilder     = $documentBuilder;
    }

    /**
     * Indexes a single record in the search engine.
     *
     * This method performs the complete indexing process for a single record:
     *
     * 1. Creates a search engine instance based on the indexing service configuration
     * 2. Builds a document from the record using the document builder
     * 3. Opens the appropriate index in the search engine
     * 4. Updates the document in the search engine
     * 5. Commits the changes and closes the index
     *
     * @param IndexingService     $indexingService The indexing service configuration to use
     * @param array<string,mixed> $record          The record data to be indexed, containing
     *                                             database fields and values
     *
     * @return bool True if indexing was successful, false otherwise
     */
    #[Override]
    public function indexRecord(IndexingService $indexingService, array $record): bool
    {
        $searchEngineService = $this->searchEngineFactory
            ->makeInstanceBySearchEngineModel($indexingService->getSearchEngine());

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return false;
        }

        // Build the document
        $document = $this->documentBuilder
            ->setIndexer($this)
            ->setRecord($record)
            ->setIndexingService($indexingService)
            ->assemble()
            ->getDocument();

        $searchEngineService->indexOpen(
            $indexingService->getSearchEngine()->getIndexName()
        );

        $result = $searchEngineService->documentUpdate($document);

        $searchEngineService->indexCommit();
        $searchEngineService->indexClose();

        return $result;
    }

    /**
     * Creates a new instance of the indexer with the specified indexing service.
     *
     * This method implements the immutable pattern by creating a clone of the current
     * indexer instance with a different indexing service configuration. This allows
     * for fluent method chaining without modifying the original instance.
     *
     * @param IndexingService $indexingService The indexing service configuration to use
     *
     * @return IndexerInterface A new instance with the specified indexing service
     */
    #[Override]
    public function withIndexingService(IndexingService $indexingService): IndexerInterface
    {
        $clone                  = clone $this;
        $clone->indexingService = $indexingService;

        return $clone;
    }

    /**
     * Creates a new instance of the indexer with the specified hidden pages exclusion setting.
     *
     * This method implements the immutable pattern by creating a clone of the current
     * indexer instance with a different setting for excluding hidden pages. This allows
     * for fluent method chaining without modifying the original instance.
     *
     * @param bool $excludeHiddenPages Whether to exclude hidden pages from indexing
     *
     * @return IndexerInterface A new instance with the specified hidden pages exclusion setting
     */
    #[Override]
    public function withExcludeHiddenPages(bool $excludeHiddenPages): IndexerInterface
    {
        $clone                     = clone $this;
        $clone->excludeHiddenPages = $excludeHiddenPages;

        return $clone;
    }

    /**
     * Removes a single record from the indexing queue.
     *
     * This method deletes the queue item for a specific record, effectively
     * removing it from the list of records to be indexed.
     *
     * @param int $recordUid The unique identifier of the record to remove from the queue
     *
     * @return IndexerInterface The current indexer instance for method chaining
     *
     * @throws RuntimeException If no indexing service is set
     * @throws Exception        If a database error occurs
     */
    #[Override]
    public function dequeueOne(int $recordUid): IndexerInterface
    {
        if (!($this->indexingService instanceof IndexingService)) {
            throw new RuntimeException('Missing indexing service instance.');
        }

        $this->queueItemRepository
            ->deleteByTableAndRecordUIDs(
                $this->getTable(),
                [
                    $recordUid,
                ],
                (int) $this->indexingService->getUid(),
            );

        return $this;
    }

    /**
     * Removes multiple records from the indexing queue.
     *
     * This method deletes the queue items for a set of records, effectively
     * removing them from the list of records to be indexed.
     *
     * @param int[] $recordUids An array of record UIDs to remove from the queue
     *
     * @return IndexerInterface The current indexer instance for method chaining
     *
     * @throws RuntimeException If no indexing service is set
     * @throws Exception        If a database error occurs
     */
    #[Override]
    public function dequeueMultiple(array $recordUids): IndexerInterface
    {
        if (!($this->indexingService instanceof IndexingService)) {
            throw new RuntimeException('Missing indexing service instance.');
        }

        $this->queueItemRepository
            ->deleteByTableAndRecordUIDs(
                $this->getTable(),
                $recordUids,
                (int) $this->indexingService->getUid(),
            );

        return $this;
    }

    /**
     * Removes all records of this indexer's type from the indexing queue.
     *
     * This method deletes all queue items associated with the current
     * indexing service and table, effectively clearing the queue for
     * this specific indexer configuration.
     *
     * @return IndexerInterface The current indexer instance for method chaining
     *
     * @throws RuntimeException If no indexing service is set
     * @throws Exception        If a database error occurs
     */
    #[Override]
    public function dequeueAll(): IndexerInterface
    {
        if (!($this->indexingService instanceof IndexingService)) {
            throw new RuntimeException('Missing indexing service instance.');
        }

        $this->queueItemRepository
            ->deleteByIndexingService($this->indexingService);

        return $this;
    }

    /**
     * Adds a single record to the indexing queue.
     *
     * This method creates a queue item for a specific record, marking it
     * for indexing in the next indexing run.
     *
     * @param int $recordUid The unique identifier of the record to add to the queue
     *
     * @return int The number of records successfully added to the queue (0 or 1)
     *
     * @throws RuntimeException If no indexing service is set
     * @throws Exception        If a database error occurs
     */
    #[Override]
    public function enqueueOne(int $recordUid): int
    {
        if (!($this->indexingService instanceof IndexingService)) {
            throw new RuntimeException('Missing indexing service instance.');
        }

        $queueItemRecord = $this->initQueueItemRecord($recordUid);

        if ($queueItemRecord === false) {
            return 0;
        }

        return $this->queueItemRepository
            ->insert($queueItemRecord);
    }

    /**
     * Adds multiple records to the indexing queue.
     *
     * This method creates queue items for a set of records, marking them
     * for indexing in the next indexing run.
     *
     * @param int[] $recordUids An array of record UIDs to add to the queue
     *
     * @return int The number of records successfully added to the queue
     *
     * @throws RuntimeException If no indexing service is set
     * @throws Exception        If a database error occurs
     */
    #[Override]
    public function enqueueMultiple(array $recordUids): int
    {
        if (!($this->indexingService instanceof IndexingService)) {
            throw new RuntimeException('Missing indexing service instance.');
        }

        return $this->queueItemRepository
            ->bulkInsert(
                $this->initQueueItemRecords($recordUids)
            );
    }

    /**
     * Adds all eligible records of this indexer's type to the indexing queue.
     *
     * This method creates queue items for all records that match the indexing
     * criteria defined by the current indexing service configuration.
     *
     * @return int The number of records successfully added to the queue
     *
     * @throws RuntimeException If no indexing service is set
     * @throws Exception        If a database error occurs
     */
    #[Override]
    public function enqueueAll(): int
    {
        if (!($this->indexingService instanceof IndexingService)) {
            throw new RuntimeException('Missing indexing service instance.');
        }

        return $this->queueItemRepository
            ->bulkInsert(
                $this->initQueueItemRecords()
            );
    }

    /**
     * Prepares a single record for addition to the indexing queue.
     *
     * This method retrieves a record from the database and formats it for
     * insertion into the indexing queue. It applies page constraints and
     * additional indexer-specific constraints to ensure only eligible
     * records are queued.
     *
     * @param int $recordUid The unique identifier of the record to prepare
     *
     * @return array<array-key, int|string>|false The prepared record data or false if not found/eligible
     *
     * @throws Exception If a database error occurs
     */
    protected function initQueueItemRecord(int $recordUid): array|bool
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($this->getTable());

        $constraints = array_merge(
            [],
            $this->getPagesQueryConstraint($queryBuilder),
            $this->getAdditionalQueryConstraints($queryBuilder),
        );

        $constraints[] = $queryBuilder->expr()->eq(
            'uid',
            $recordUid,
        );

        return $this
            ->fetchRecords($queryBuilder, $constraints)
            ->fetchAssociative();
    }

    /**
     * Prepares multiple records for addition to the indexing queue.
     *
     * This method retrieves records from the database and formats them for
     * insertion into the indexing queue. It applies page constraints and
     * additional indexer-specific constraints to ensure only eligible
     * records are queued.
     *
     * If no record UIDs are provided, all eligible records (based on the
     * constraints) will be prepared for queuing.
     *
     * @param int[] $recordUids Optional array of record UIDs to prepare
     *
     * @return array<array-key, array<string, int|string>> Array of prepared record data
     *
     * @throws Exception If a database error occurs
     */
    protected function initQueueItemRecords(array $recordUids = []): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($this->getTable());

        $constraints = array_merge(
            [],
            $this->getPagesQueryConstraint($queryBuilder),
            $this->getAdditionalQueryConstraints($queryBuilder)
        );

        if ($recordUids !== []) {
            $constraints[] = $queryBuilder->expr()->in(
                'uid',
                $recordUids,
            );
        }

        return $this
            ->fetchRecords($queryBuilder, $constraints)
            ->fetchAllAssociative();
    }

    /**
     * Executes a database query to fetch records for indexing.
     *
     * This method prepares and executes a database query to retrieve records
     * that match the given constraints. It sets up the query with the appropriate
     * restrictions, selects the necessary fields, and formats the results for
     * use in the indexing queue.
     *
     * The method adds several literal fields to the query:
     *
     * - table_name: The name of the table being queried
     * - service_uid: The UID of the current indexing service
     * - changed: The timestamp when the record was last changed
     * - priority: The indexing priority for the record
     *
     * @param QueryBuilder $queryBuilder The query builder to use for the query
     * @param string[]     $constraints  An array of SQL constraint expressions
     *
     * @return Result The database query result object
     */
    private function fetchRecords(
        QueryBuilder $queryBuilder,
        array $constraints,
    ): Result {
        // Set up the query restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DefaultRestrictionContainer::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class))
        ;

        // Get the field statement for determining when a record was last changed
        $changedFieldStatement = $this->getChangedFieldStatement();

        if ($changedFieldStatement === null) {
            $changedFieldStatement = 0;
        }

        // Get the current indexing service UID
        $serviceUid = $this->indexingService?->getUid() ?? 0;

        // Prepare the literal fields to select
        $selectLiterals = [
            "'" . $this->getTable() . "' as table_name",
            "'" . $serviceUid . "' AS service_uid",
            $changedFieldStatement . ' AS changed',
            "'" . $this->getPriority() . "' AS priority",
        ];

        // Build and execute the query
        return $queryBuilder
            ->select('uid AS record_uid')
            ->addSelectLiteral(...$selectLiterals)
            ->from($this->getTable())
            ->where(...$constraints)
            ->executeQuery();
    }

    /**
     * Returns the indexing priority for records processed by this indexer.
     *
     * The priority determines the order in which records are processed during
     * indexing. Higher priority records are processed first.
     *
     * @return int The indexing priority (0 = normal priority)
     */
    protected function getPriority(): int
    {
        // TODO Currently not used
        return 0;
    }

    /**
     * Returns the query constraints for filtering records by page.
     *
     * This method creates SQL constraints to limit the records to those
     * that belong to the pages configured in the indexing service. For
     * page records themselves, it filters by UID; for other records,
     * it filters by PID (parent ID).
     *
     * @param QueryBuilder $queryBuilder The query builder to use for creating expressions
     *
     * @return string[] Array of SQL constraint expressions
     */
    protected function getPagesQueryConstraint(QueryBuilder $queryBuilder): array
    {
        $pageUIDs    = $this->getPages();
        $constraints = [];

        if ($pageUIDs !== []) {
            $constraints[] = $queryBuilder->expr()->in(
                ($this->getTable() === PageIndexer::TABLE) ? 'uid' : 'pid',
                $pageUIDs,
            );
        }

        return $constraints;
    }

    /**
     * Returns additional query constraints specific to this indexer.
     *
     * This method can be overridden by concrete indexer implementations
     * to add custom filtering logic. The default implementation returns
     * an empty array (no additional constraints).
     *
     * @param QueryBuilder $queryBuilder The query builder to use for creating expressions
     *
     * @return string[] Array of SQL constraint expressions
     */
    protected function getAdditionalQueryConstraints(QueryBuilder $queryBuilder): array
    {
        return [];
    }

    /**
     * Returns all page UIDs that should be considered for indexing.
     *
     * This method combines individually selected pages and recursively selected
     * page trees from the indexing service configuration. For recursive selections,
     * it retrieves all subpages up to a depth of 99 levels.
     *
     * The method respects the excludeHiddenPages setting when retrieving
     * pages recursively.
     *
     * @return int[] Array of page UIDs to include in indexing
     */
    private function getPages(): array
    {
        // Get individually selected pages
        $pagesSingle = GeneralUtility::intExplode(
            ',',
            $this->indexingService?->getPagesSingle() ?? '',
            true
        );

        // Get recursively selected page trees
        $pagesRecursive = GeneralUtility::intExplode(
            ',',
            $this->indexingService?->getPagesRecursive() ?? '',
            true
        );

        // Recursively determine all associated pages and subpages
        $pageIds   = [[]];
        $pageIds[] = $pagesSingle;
        $pageIds[] = $this->pageRepository
            ->getPageIdsRecursive(
                $pagesRecursive,
                // Maximum depth of 99 levels
                99,
                // Include the parent pages
                true,
                // Whether to exclude hidden pages
                $this->excludeHiddenPages
            );

        // Merge all page IDs and filter out empty values
        return array_filter(
            array_merge(...$pageIds)
        );
    }

    /**
     * Returns the SQL expression to determine when a record was last changed.
     *
     * This method creates an SQL expression that determines the most recent
     * timestamp between a record's creation/start time and its last modification
     * time. This is used to track when records need to be re-indexed.
     *
     * If the table has a starttime column, the method returns a GREATEST()
     * expression comparing starttime and tstamp. Otherwise, it returns just
     * the tstamp column.
     *
     * @return string|null SQL expression for the changed timestamp, or null if not available
     */
    protected function getChangedFieldStatement(): ?string
    {
        // Check if the table has a starttime column
        if (
            isset($GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime'])
            && ($GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime'] !== '')
        ) {
            // Return the greater of starttime and tstamp
            return 'GREATEST(' . $GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime']
                . ', ' . $GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'] . ')';
        }

        // Return just the tstamp column
        return $GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'];
    }
}
