<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Service\Indexer;

use ArrayObject;
use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

use function array_map;
use function count;
use function intval;
use function range;
use function sort;

/**
 * Functional tests for PageIndexer.
 *
 * Tests enqueue/dequeue operations with real database queries. Verifies that
 * page records are correctly added to and removed from the indexing queue,
 * respecting page constraints (doktype, no_search, hidden, recycler).
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(PageIndexer::class)]
final class PageIndexerTest extends AbstractFunctionalTestCase
{
    private const string QUEUE_TABLE = 'tx_typo3searchalgolia_domain_model_queueitem';

    private PageIndexer $pageIndexer;

    private IndexingService $indexingService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();

        $this->pageIndexer = new PageIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->get(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );

        // Get real IndexingService (uid=1, type=pages, pages_recursive=1, pages_doktype=1)
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(1);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }

    // -----------------------------------------------------------------------
    // enqueueOne
    // -----------------------------------------------------------------------

    /**
     * Tests that enqueueOne() adds a single page record to the indexing
     * queue with the correct table name, record UID and service UID.
     */
    #[Test]
    public function enqueueOneAddsPageToQueue(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(2);

        self::assertSame(1, $result);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);

        self::assertNotFalse($row);
        self::assertSame('pages', $row['table_name']);
        self::assertSame(1, (int) $row['service_uid']);
    }

    /**
     * Tests that enqueueOne() reports a page that is already in the queue as
     * queued, so that zero stays reserved for records the indexer rejects.
     */
    #[Test]
    public function enqueueOneReportsAlreadyQueuedPageAsQueued(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        self::assertSame(1, $indexer->enqueueOne(2));
        self::assertSame(1, $indexer->enqueueOne(2));

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::QUEUE_TABLE);
        $count        = (int) $queryBuilder
            ->count('*')
            ->from(self::QUEUE_TABLE)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $count);
    }

    /**
     * Tests that enqueueOne() returns zero when the specified page
     * does not exist in the database.
     */
    #[Test]
    public function enqueueOneReturnsZeroForNonExistentPage(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(999);

        self::assertSame(0, $result);
    }

    /**
     * Tests that enqueueOne() returns zero for a page with no_search=1,
     * since such pages are excluded by the PageIndexer constraints.
     */
    #[Test]
    public function enqueueOneReturnsZeroForNoSearchPage(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        // Page uid=6 has no_search=1, so PageIndexer's constraint excludes it
        $result = $indexer->enqueueOne(6);

        self::assertSame(0, $result);
    }

    /**
     * Tests that enqueueOne() returns zero for a recycler page
     * (doktype=255), which is not in the allowed doktype list.
     */
    #[Test]
    public function enqueueOneReturnsZeroForRecyclerPage(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        // Page uid=5 has doktype=255, not in allowed list (pages_doktype=1)
        $result = $indexer->enqueueOne(5);

        self::assertSame(0, $result);
    }

    // -----------------------------------------------------------------------
    // enqueueEligible / enqueueAll
    // -----------------------------------------------------------------------

    /**
     * Tests that enqueueEligible() adds multiple page records to
     * the indexing queue in a single operation.
     */
    #[Test]
    public function enqueueEligibleAddsMultiplePages(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueEligible([2, 3]);

        self::assertSame([2, 3], $result);

        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);
        $row3 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 3);

        self::assertNotFalse($row2);
        self::assertNotFalse($row3);
    }

    /**
     * Tests that enqueueAll() adds all eligible pages, excluding hidden,
     * recycler, and no_search pages, to the indexing queue.
     */
    #[Test]
    public function enqueueAllAddsAllEligiblePages(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        // Eligible: uid=1 (root, doktype=1), uid=2 (sub, doktype=1), uid=3 (deep, doktype=1)
        // Not eligible: uid=4 (hidden), uid=5 (doktype=255), uid=6 (no_search=1)
        $result = $indexer->enqueueAll();

        self::assertSame(3, $result);
    }

    /**
     * Tests that the page tree of the indexing service is read once for a list
     * that is longer than one block, and not once per block.
     */
    #[Test]
    public function enqueueEligibleReadsThePageTreeOnceForAllBlocks(): void
    {
        $calls = new ArrayObject();

        $pageRepository = new readonly class($this->getConnectionPool(), $calls) extends PageRepository {
            /**
             * @param ConnectionPool              $connectionPool The connection pool
             * @param ArrayObject<int, list<int>> $calls          Receives the page lists the walk was asked for
             */
            public function __construct(
                ConnectionPool $connectionPool,
                private ArrayObject $calls,
            ) {
                parent::__construct($connectionPool);
            }

            /**
             * Records the page lists the walk is asked for and leaves the walk itself alone.
             *
             * @param int[]       $pageIds              The pages to start from
             * @param int<0, max> $depth                The depth of the walk
             * @param bool        $includeAncestorPages Whether the start pages are part of the result
             * @param bool        $excludeHiddenPages   Whether hidden pages end the walk
             *
             * @return int[]
             */
            public function getPageIdsRecursive(
                array $pageIds,
                int $depth,
                bool $includeAncestorPages = true,
                bool $excludeHiddenPages = false,
            ): array {
                $this->calls->append($pageIds);

                return parent::getPageIdsRecursive($pageIds, $depth, $includeAncestorPages, $excludeHiddenPages);
            }
        };

        $indexer = new PageIndexer(
            $this->getConnectionPool(),
            $this->createMock(SiteFinder::class),
            $pageRepository,
            $this->createMock(SearchEngineFactory::class),
            $this->get(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );

        // More records than one block of the lookup holds
        $recordUids = [2, ...range(1000, 2500), 3];

        self::assertGreaterThan(Constants::IN_LIST_BLOCK_SIZE, count($recordUids), 'The last record is behind the first block');

        $accepted = $indexer
            ->withIndexingService($this->indexingService)
            ->enqueueEligible($recordUids);

        sort($accepted);

        self::assertSame([2, 3], $accepted);
        self::assertCount(1, $calls, 'The page tree is read once for both blocks');
    }

    /**
     * Tests that enqueueEligible() queues nothing for an empty list and does
     * not read it as every page, and that the pages queued before stay.
     */
    #[Test]
    public function enqueueEligibleQueuesNothingForAnEmptyList(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueOne(2);

        self::assertSame([], $indexer->enqueueEligible([]));

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::QUEUE_TABLE);
        $recordUids   = $queryBuilder
            ->select('record_uid')
            ->from(self::QUEUE_TABLE)
            ->executeQuery()
            ->fetchFirstColumn();

        self::assertSame([2], array_map(intval(...), $recordUids));
    }

    /**
     * Tests that enqueueEligible() queues the pages the indexer accepts and
     * returns exactly their UIDs, and leaves the rejected pages and the pages
     * that do not exist out of the queue.
     */
    #[Test]
    public function enqueueEligibleQueuesAndReturnsTheAcceptedPages(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        // Page 4 is hidden, 5 is a recycler, 6 has no_search and 999 does not exist
        $accepted = $indexer->enqueueEligible([2, 3, 4, 5, 6, 999]);

        sort($accepted);

        self::assertSame([2, 3], $accepted);
        self::assertNotFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2));
        self::assertNotFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 3));

        foreach ([4, 5, 6, 999] as $uid) {
            self::assertFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', $uid));
        }
    }

    /**
     * Tests that enqueueEligible() counts a page that is queued already as
     * accepted, so that a caller does not take it for a rejected one.
     */
    #[Test]
    public function enqueueEligibleCountsAnAlreadyQueuedPageAsAccepted(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueOne(2);

        $accepted = $indexer->enqueueEligible([2, 3]);

        sort($accepted);

        self::assertSame([2, 3], $accepted);
    }

    /**
     * Tests that enqueueEligible() returns nothing for an empty list and
     * queues nothing.
     */
    #[Test]
    public function enqueueEligibleReturnsNothingForAnEmptyList(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        self::assertSame([], $indexer->enqueueEligible([]));
        self::assertFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'table_name', 'pages'));
    }

    /**
     * Tests that enqueueEligible() returns nothing when the indexer rejects
     * every page of the list.
     */
    #[Test]
    public function enqueueEligibleReturnsNothingWhenEveryPageIsRejected(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        self::assertSame([], $indexer->enqueueEligible([4, 5, 6]));
        self::assertFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'table_name', 'pages'));
    }

    /**
     * Tests that enqueueEligible() returns a page once although it is given
     * twice, and that a list longer than one block is decided completely. The test
     * database takes the whole list in one statement, so this pins the answer and
     * not the size of the block.
     */
    #[Test]
    public function enqueueEligibleReturnsEachPageOnceAndDecidesLongLists(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $recordUids = [2, ...range(1000, 2500), 2, 3];

        self::assertGreaterThan(Constants::IN_LIST_BLOCK_SIZE, count($recordUids), 'The list is longer than one block');

        $accepted = $indexer->enqueueEligible($recordUids);

        sort($accepted);

        self::assertSame([2, 3], $accepted);
    }

    // -----------------------------------------------------------------------
    // dequeueOne / dequeueMultiple / dequeueAll
    // -----------------------------------------------------------------------

    /**
     * Tests that dequeueOne() removes a single page record from
     * the indexing queue by its record UID.
     */
    #[Test]
    public function dequeueOneRemovesPageFromQueue(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueOne(2);

        $indexer->dequeueOne(2);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);

        self::assertFalse($row);
    }

    /**
     * Tests that dequeueMultiple() removes only the specified page
     * records from the queue while leaving others untouched.
     */
    #[Test]
    public function dequeueMultipleRemovesMultiplePages(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueEligible([1, 2, 3]);

        $indexer->dequeueMultiple([1, 3]);

        $row1 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);
        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);
        $row3 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 3);

        self::assertFalse($row1);
        self::assertNotFalse($row2);
        self::assertFalse($row3);
    }

    /**
     * Tests that dequeueAll() removes all queue items associated with
     * the indexing service, regardless of individual record UIDs.
     */
    #[Test]
    public function dequeueAllRemovesAllPagesForService(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueAll();

        $indexer->dequeueAll();

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::QUEUE_TABLE);
        $count        = (int) $queryBuilder
            ->count('*')
            ->from(self::QUEUE_TABLE)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, $count);
    }

    /**
     * Tests that the page tree that was read for a run of the queue is not kept
     * for what is asked afterwards, so a page that left the selected tree after
     * the run is rejected.
     */
    #[Test]
    public function thePageTreeOfAQueueRunIsNotKeptAfterTheRun(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        self::assertSame([2], $indexer->enqueueEligible([2]));

        // Page 3 leaves the tree of the indexing service, which selects page 1 recursively
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 0], ['uid' => 3]);

        self::assertSame(0, $indexer->enqueueOne(3));
    }

    /**
     * Tests that the pages of a list are matched against the selected pages of the
     * service, so that a page outside of the selected tree is left out of the answer
     * and of the queue.
     */
    #[Test]
    public function enqueueEligibleLeavesOutAPageOutsideOfTheSelectedTree(): void
    {
        // Only page 3 and what lies below it is selected, page 2 is visible and outside of it
        $this->indexingService->setPagesRecursive('3');

        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        self::assertSame([3], $indexer->enqueueEligible([2, 3]));
        self::assertNotFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 3));
        self::assertFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2));
    }
}
