<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Functional tests for ContentIndexer.
 *
 * Tests enqueue/dequeue operations for tt_content records with real database
 * queries. Verifies that content elements are correctly added to and removed
 * from the indexing queue, respecting page constraints.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(ContentIndexer::class)]
final class ContentIndexerTest extends AbstractFunctionalTestCase
{
    private const string QUEUE_TABLE = 'tx_typo3searchalgolia_domain_model_queueitem';

    private ContentIndexer $contentIndexer;

    private IndexingService $indexingService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();

        $this->contentIndexer = new ContentIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->get(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );

        // Get real IndexingService (uid=2, type=tt_content, pages_recursive=1)
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(2);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }

    // -----------------------------------------------------------------------
    // enqueueOne
    // -----------------------------------------------------------------------

    /**
     * Tests that enqueueOne() adds a single content element to the indexing
     * queue with the correct table name, record UID and service UID.
     */
    #[Test]
    public function enqueueOneAddsContentToQueue(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(1);

        self::assertSame(1, $result);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);

        self::assertNotFalse($row);
        self::assertSame('tt_content', $row['table_name']);
        self::assertSame(2, (int) $row['service_uid']);
    }

    /**
     * Tests that enqueueOne() returns zero when the specified content
     * element does not exist in the database.
     */
    #[Test]
    public function enqueueOneReturnsZeroForNonExistentContent(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(999);

        self::assertSame(0, $result);
    }

    /**
     * Adds a content element to a page.
     *
     * @param int $uid The UID of the content element
     * @param int $pid The UID of the page
     *
     * @return void
     */
    private function insertContentElement(
        int $uid,
        int $pid,
    ): void {
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'     => $uid,
                    'pid'     => $pid,
                    'header'  => 'Content ' . $uid,
                    'CType'   => 'text',
                    'deleted' => 0,
                    'hidden'  => 0,
                    'colPos'  => 0,
                ]
            );
    }

    /**
     * Tests that a content element on a hidden page is not queued, because
     * the page itself is not indexed and the content would reach the search
     * without a page.
     */
    #[Test]
    public function enqueueOneSkipsContentOnAHiddenPage(): void
    {
        // Page 4 is hidden
        $this->insertContentElement(60, 4);

        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        self::assertSame(0, $indexer->enqueueOne(60));
        self::assertSame(1, $indexer->enqueueOne(1), 'Content on a visible page is still queued');
    }

    /**
     * Tests that the batch and the full queueing skip content on a hidden page as well.
     */
    #[Test]
    public function enqueueEligibleAndEnqueueAllSkipContentOnAHiddenPage(): void
    {
        $this->insertContentElement(60, 4);

        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        self::assertSame([1], $indexer->enqueueEligible([1, 60]));

        $indexer->enqueueAll();

        self::assertFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 60));
        self::assertNotFalse(
            $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2),
            'Content on a visible page is queued by enqueueAll'
        );
    }

    /**
     * Tests that the content elements of a list are matched against the pages of the
     * service by the page they are stored on, and not by their own UID. The UID of an
     * element is no page UID here, and an element on a page outside of the selected
     * tree is left out.
     */
    #[Test]
    public function enqueueEligibleMatchesTheElementsOfAListByTheirPage(): void
    {
        // Only page 3 and what lies below it is selected
        $this->indexingService->setPagesRecursive('3');

        $this->insertContentElement(500, 3);
        $this->insertContentElement(501, 2);

        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        self::assertSame([500], $indexer->enqueueEligible([500, 501]));
        self::assertNotFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 500));
        self::assertFalse($this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 501));
    }

    /**
     * Tests that a service that selects no page accepts the elements of a list, like the
     * queue run of the whole table does.
     */
    #[Test]
    public function enqueueEligibleAcceptsEveryElementForAServiceWithoutSelectedPages(): void
    {
        $this->indexingService->setPagesRecursive('');
        $this->indexingService->setPagesSingle('');

        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        self::assertSame([1, 2], $indexer->enqueueEligible([1, 2]));
    }

    /**
     * Tests that a content element on a page that is excluded from the search
     * is not queued, like the content of a hidden page. Saving such a page
     * removes its content elements from the queue and the index, and a later
     * save of one of them must not bring them back.
     */
    #[Test]
    public function enqueueOneSkipsContentOnAPageExcludedFromTheSearch(): void
    {
        // Page 6 is excluded from the search
        $this->insertContentElement(63, 6);

        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        self::assertSame(0, $indexer->enqueueOne(63));
        self::assertSame(1, $indexer->enqueueOne(1), 'Content on a visible page is still queued');
    }

    /**
     * Tests that a content element on a page that is not published at the moment is
     * not queued, because the page itself is not indexed then. A page that is published
     * for a window that holds the present keeps its content.
     */
    #[Test]
    public function enqueueOneSkipsContentOnAPageOutsideOfItsPublicationWindow(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');

        // A page that has ended, one that has not started and one that is published now
        foreach ([70 => [0, 1000], 71 => [4102444800, 0], 72 => [1000, 4102444800]] as $uid => [$starttime, $endtime]) {
            $pages->insert(
                'pages',
                [
                    'uid'       => $uid,
                    'pid'       => 1,
                    'title'     => 'Window ' . $uid,
                    'doktype'   => 1,
                    'hidden'    => 0,
                    'no_search' => 0,
                    'starttime' => $starttime,
                    'endtime'   => $endtime,
                ]
            );

            $this->insertContentElement(600 + $uid, $uid);
        }

        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        self::assertSame(0, $indexer->enqueueOne(670), 'The page has ended');
        self::assertSame(0, $indexer->enqueueOne(671), 'The page has not started');
        self::assertSame(1, $indexer->enqueueOne(672), 'The page is published now');
    }

    /**
     * Tests that content on a visible page below a hidden page is still queued,
     * because only the hidden page itself is left out.
     */
    #[Test]
    public function enqueueOneKeepsContentOnAVisiblePageBelowAHiddenPage(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'       => 61,
                    'pid'       => 4,
                    'title'     => 'Visible Below Hidden',
                    'doktype'   => 1,
                    'deleted'   => 0,
                    'hidden'    => 0,
                    'no_search' => 0,
                ]
            );
        $this->insertContentElement(62, 61);

        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        self::assertSame(1, $indexer->enqueueOne(62));
    }

    // -----------------------------------------------------------------------
    // enqueueEligible / enqueueAll
    // -----------------------------------------------------------------------

    /**
     * Tests that enqueueEligible() adds multiple content elements to
     * the indexing queue in a single operation.
     */
    #[Test]
    public function enqueueEligibleAddsMultipleContentElements(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueEligible([1, 2]);

        self::assertSame([1, 2], $result);

        $row1 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);
        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);

        self::assertNotFalse($row1);
        self::assertNotFalse($row2);
    }

    /**
     * Tests that enqueueAll() adds all eligible content elements from
     * pages within the recursive page tree to the indexing queue.
     */
    #[Test]
    public function enqueueAllAddsAllEligibleContent(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        // All 5 content elements are on pages within the recursive tree
        // uid=1 (pid=2), uid=2 (pid=2), uid=3 (pid=3), uid=4 (pid=2), uid=5 (pid=2)
        $result = $indexer->enqueueAll();

        self::assertSame(5, $result);
    }

    // -----------------------------------------------------------------------
    // dequeueOne / dequeueMultiple / dequeueAll
    // -----------------------------------------------------------------------

    /**
     * Tests that dequeueOne() removes a single content element
     * from the indexing queue by its record UID.
     */
    #[Test]
    public function dequeueOneRemovesContentFromQueue(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueOne(1);

        $indexer->dequeueOne(1);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);

        self::assertFalse($row);
    }

    /**
     * Tests that dequeueMultiple() removes only the specified content
     * elements from the queue while leaving others untouched.
     */
    #[Test]
    public function dequeueMultipleRemovesMultipleContentElements(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);
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
    public function dequeueAllRemovesAllContentForService(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);
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
}
