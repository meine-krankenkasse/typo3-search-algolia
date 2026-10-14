<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record\RecordMoveEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AddsInlineChildRelationTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

use function array_filter;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function sort;
use function str_contains;
use function str_starts_with;

/**
 * Functional tests for RecordMoveEventListener.
 *
 * Tests the move flow end-to-end with real DB queries for page record
 * lookup and root page resolution. Mocks IndexerFactory and SearchEngineFactory
 * to avoid external service calls.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordMoveEventListener::class)]
final class RecordMoveEventListenerTest extends AbstractFunctionalTestCase
{
    use AddsInlineChildRelationTrait;

    /**
     * The indexer factory that hands out the indexer mock.
     */
    private MockObject&IndexerFactory $indexerFactoryMock;

    /**
     * The search engine factory that hands out the search engine mock.
     */
    private MockObject&SearchEngineFactory $searchEngineFactoryMock;

    /**
     * The indexer that the listener asks, configured per test.
     */
    private MockObject&IndexerInterface $indexerMock;

    /**
     * The listener under test.
     */
    private RecordMoveEventListener $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();
        $pageRepository = new PageRepository($connectionPool);

        $this->indexerMock = $this->createMock(IndexerInterface::class);
        $this->indexerMock
            ->method('getTable')
            ->willReturn('pages');
        $this->indexerMock
            ->method('withIndexingService')
            ->willReturn($this->indexerMock);

        $this->indexerFactoryMock      = $this->createMock(IndexerFactory::class);
        $this->searchEngineFactoryMock = $this->createMock(SearchEngineFactory::class);

        $indexingServiceRepository = $this->get(IndexingServiceRepository::class);

        $recordHandler = new RecordHandler(
            $this->searchEngineFactoryMock,
            $this->indexerFactoryMock,
            $pageRepository,
            $indexingServiceRepository,
            new ContentRepository($connectionPool),
        );

        $this->subject = new RecordMoveEventListener(
            $recordHandler,
            $pageRepository,
            new RecordRepository($connectionPool),
        );
    }

    /**
     * Tests that the listener dequeues and re-enqueues a page record
     * when a DataHandlerRecordMoveEvent is dispatched with a new target.
     */
    #[Test]
    public function invokeUpdatesRecordInQueueOnMove(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->expects(self::atLeastOnce())
            ->method('dequeueMultiple')
            ->with([4])
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->expects(self::atLeastOnce())
            ->method('enqueueEligible')
            ->with([4])
            ->willReturnCallback(static fn (array $uids): array => $uids);

        $event = new DataHandlerRecordMoveEvent('pages', 4, 3);
        $event->setPreviousPid(1);

        ($this->subject)($event);
    }

    /**
     * Tests that a record that moved to another site is removed from the index
     * of its former site, which the services of the new site never look at.
     */
    #[Test]
    public function invokeRemovesTheDocumentFromTheIndexOfTheFormerSite(): void
    {
        $connectionPool = $this->getConnectionPool();

        $connectionPool
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => 10,
                    'pid'         => 0,
                    'title'       => 'Former Site Root',
                    'is_siteroot' => 1,
                    'doktype'     => 1,
                    'deleted'     => 0,
                    'hidden'      => 0,
                    'no_search'   => 0,
                ]
            );

        $connectionPool
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_searchengine')
            ->insert(
                'tx_typo3searchalgolia_domain_model_searchengine',
                [
                    'uid'        => 2,
                    'pid'        => 1,
                    'title'      => 'Former Site Engine',
                    'engine'     => 'algolia',
                    'index_name' => 'former_index',
                    'deleted'    => 0,
                ]
            );

        $connectionPool
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 4,
                    'pid'             => 10,
                    'title'           => 'Former Site Page Indexer',
                    'type'            => 'pages',
                    'search_engine'   => 2,
                    'deleted'         => 0,
                    'pages_recursive' => '10',
                    'pages_doktype'   => '1',
                ]
            );

        // The service of the new site accepts the page, the one of the former site does not
        $acceptingIndexer = $this->createMock(IndexerInterface::class);
        $acceptingIndexer->method('dequeueOne')->willReturn($acceptingIndexer);
        $acceptingIndexer->method('enqueueOne')->willReturn(1);
        $acceptingIndexer->method('enqueueEligible')->willReturnCallback(static fn (array $uids): array => $uids);

        $rejectingIndexer = $this->createMock(IndexerInterface::class);
        $rejectingIndexer->method('dequeueOne')->willReturn($rejectingIndexer);
        $rejectingIndexer->method('enqueueOne')->willReturn(0);
        $rejectingIndexer->method('enqueueEligible')->willReturnCallback(static fn (): array => []);

        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                static fn (IndexingService $service): IndexerInterface => ((int) $service->getUid() === 4)
                    ? $rejectingIndexer
                    : $acceptingIndexer
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);

        $indexNames   = [];
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturnCallback(
                static function (string $indexName) use (&$indexNames, $searchEngine): SearchEngineInterface {
                    $indexNames[] = $indexName;

                    return $searchEngine;
                }
            );
        $searchEngine
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [4]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        // Page 4 has no subpages and no content, it lives below the first site and came from below the former site
        $event = new DataHandlerRecordMoveEvent('pages', 4, 1);
        $event->setPreviousPid(10);

        ($this->subject)($event);

        self::assertSame(['former_index'], $indexNames);
    }

    /**
     * Inserts a page below the given page.
     *
     * @param int $uid        The UID of the page
     * @param int $pid        The UID of the parent page
     * @param int $isSiteRoot Whether the page is the root of a site
     *
     * @return void
     */
    private function insertPage(
        int $uid,
        int $pid,
        int $isSiteRoot = 0,
    ): void {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => $uid,
                    'pid'         => $pid,
                    'title'       => 'Page ' . $uid,
                    'doktype'     => 1,
                    'is_siteroot' => $isSiteRoot,
                ]
            );
    }

    /**
     * Inserts a second site with a page indexing service, which writes to an
     * index of its own.
     *
     * @return void
     */
    private function insertFormerSite(): void
    {
        $connectionPool = $this->getConnectionPool();

        $connectionPool
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => 10,
                    'pid'         => 0,
                    'title'       => 'Former Site Root',
                    'is_siteroot' => 1,
                    'doktype'     => 1,
                    'deleted'     => 0,
                    'hidden'      => 0,
                    'no_search'   => 0,
                ]
            );

        $connectionPool
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_searchengine')
            ->insert(
                'tx_typo3searchalgolia_domain_model_searchengine',
                [
                    'uid'        => 2,
                    'pid'        => 1,
                    'title'      => 'Former Site Engine',
                    'engine'     => 'algolia',
                    'index_name' => 'former_index',
                    'deleted'    => 0,
                ]
            );

        $connectionPool
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 4,
                    'pid'             => 10,
                    'title'           => 'Former Site Page Indexer',
                    'type'            => 'pages',
                    'search_engine'   => 2,
                    'deleted'         => 0,
                    'pages_recursive' => '10',
                    'pages_doktype'   => '1',
                ]
            );
    }

    /**
     * Tests that the page a content element was moved away from is queued
     * again by the indexing services of its own site, not by the ones of the
     * site the element moved to, which do not know the page.
     */
    #[Test]
    public function invokeRequeuesThePreviousPageOfAContentElementInItsOwnSite(): void
    {
        $this->insertFormerSite();

        $formerPageIndexer = $this->createMock(PageIndexer::class);
        $formerPageIndexer
            ->method('isIncludeContentElements')
            ->willReturn(true);
        $formerPageIndexer
            ->expects(self::once())
            ->method('dequeueOne')
            ->with(10)
            ->willReturn($formerPageIndexer);
        $formerPageIndexer
            ->expects(self::once())
            ->method('enqueueOne')
            ->with(10)
            ->willReturn(1);

        $otherIndexer = $this->createMock(IndexerInterface::class);
        $otherIndexer->method('dequeueOne')->willReturn($otherIndexer);
        $otherIndexer->method('enqueueOne')->willReturn(0);
        $otherIndexer->method('enqueueEligible')->willReturnCallback(static fn (): array => []);

        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                static fn (IndexingService $service): IndexerInterface => ((int) $service->getUid() === 4)
                    ? $formerPageIndexer
                    : $otherIndexer
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);

        // Content element 1 lives on page 2 of the first site and came from page 10 of the former site
        $event = new DataHandlerRecordMoveEvent('tt_content', 1, 2);
        $event->setPreviousPid(10);

        ($this->subject)($event);
    }

    /**
     * Tests that the site change of a moved page is detected although the
     * rootline of the page is still cached. DataHandler flushes the rootline
     * cache after the hooks ran, so the root of the page must not be read from
     * the rootline of the moved page itself.
     */
    #[Test]
    public function invokeDetectsTheSiteChangeOfAMovedPageWithACachedRootline(): void
    {
        $this->insertFormerSite();

        // A rootline cache that really stores, unlike the one of the test framework
        $this->get(CacheManager::class)->setCacheConfigurations(
            [
                'rootline' => [
                    'frontend' => VariableFrontend::class,
                    'backend'  => TransientMemoryBackend::class,
                    'options'  => [],
                    'groups'   => ['pages'],
                ],
            ]
        );

        // Fill the cache with the rootline of page 2 below the first site
        self::assertSame(1, (new PageRepository($this->getConnectionPool()))->getRootPageId(2));

        // The page moved below the root page 10 of the other site, the cache still has the old rootline
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 10], ['uid' => 2]);

        $rejectingIndexer = $this->createMock(IndexerInterface::class);
        $rejectingIndexer->method('dequeueOne')->willReturn($rejectingIndexer);
        $rejectingIndexer->method('dequeueMultiple')->willReturn($rejectingIndexer);
        $rejectingIndexer->method('enqueueOne')->willReturn(0);
        $rejectingIndexer->method('enqueueEligible')->willReturnCallback(static fn (): array => []);

        $decidedByNewSite = [];

        $acceptingIndexer = $this->createMock(IndexerInterface::class);
        $acceptingIndexer->method('dequeueOne')->willReturn($acceptingIndexer);
        $acceptingIndexer->method('dequeueMultiple')->willReturn($acceptingIndexer);
        $acceptingIndexer
            ->method('enqueueOne')
            ->willReturnCallback(
                static function (int $uid) use (&$decidedByNewSite): int {
                    $decidedByNewSite[] = $uid;

                    return 1;
                }
            );
        $acceptingIndexer
            ->method('enqueueEligible')
            ->willReturnCallback(
                static function (array $uids) use (&$decidedByNewSite): array {
                    $decidedByNewSite = [...$decidedByNewSite, ...$uids];

                    return $uids;
                }
            );

        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                static fn (IndexingService $service): IndexerInterface => ((int) $service->getUid() === 4)
                    ? $acceptingIndexer
                    : $rejectingIndexer
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);

        $indexName    = '';
        $removed      = [];
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturnCallback(
                static function (string $name) use (&$indexName, $searchEngine): SearchEngineInterface {
                    $indexName = $name;

                    return $searchEngine;
                }
            );
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$indexName, &$removed): void {
                    foreach ($uids as $uid) {
                        $removed[] = $indexName . ':' . $table . ':' . $uid;
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 10);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        // The subpage 3 left the first site, whose service rejects it, and the service of the new site accepts it
        self::assertContains('test_index:pages:3', $removed);
        self::assertContains(3, $decidedByNewSite, 'The subpage is decided by the service of the new site');
    }

    /**
     * Binds an indexer to every indexing service that records the UIDs it is asked
     * to queue, and a search engine that records the documents it removes.
     *
     * @param int[] $rejectingServices The UIDs of the services that accept no record
     *
     * @return array{enqueued: array<int, int[]>, removed: string[]} Filled while the test runs
     */
    private function &bindMoveRecorders(array $rejectingServices): array
    {
        $recorded = ['enqueued' => [], 'removed' => []];

        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                function (IndexingService $service) use (&$recorded, $rejectingServices): IndexerInterface {
                    $serviceUid = (int) $service->getUid();

                    $indexer = $this->createMock(IndexerInterface::class);
                    $indexer->method('getTable')->willReturn('pages');
                    $indexer->method('dequeueOne')->willReturn($indexer);
                    $indexer->method('dequeueMultiple')->willReturn($indexer);
                    $indexer
                        ->method('enqueueOne')
                        ->willReturnCallback(
                            static function (int $uid) use (&$recorded, $serviceUid, $rejectingServices): int {
                                $recorded['enqueued'][$serviceUid][] = $uid;

                                return in_array($serviceUid, $rejectingServices, true) ? 0 : 1;
                            }
                        );
                    $indexer
                        ->method('enqueueEligible')
                        ->willReturnCallback(
                            static function (array $uids) use (&$recorded, $serviceUid, $rejectingServices): array {
                                $recorded['enqueued'][$serviceUid] = [
                                    ...($recorded['enqueued'][$serviceUid] ?? []),
                                    ...$uids,
                                ];

                                return in_array($serviceUid, $rejectingServices, true) ? [] : $uids;
                            }
                        );

                    return $indexer;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);

        $indexName    = '';
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturnCallback(
                static function (string $name) use (&$indexName, $searchEngine): SearchEngineInterface {
                    $indexName = $name;

                    return $searchEngine;
                }
            );
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$indexName, &$recorded): void {
                    foreach ($uids as $uid) {
                        $recorded['removed'][] = $indexName . ':' . $table . ':' . $uid;
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        return $recorded;
    }

    /**
     * Tests that the subpages of a page that moved within its site are decided
     * again, because the move can take the page out of the selected page tree
     * of an indexing service. The document of a subpage that no service accepts
     * any more is removed.
     */
    #[Test]
    public function invokeDecidesTheSubpagesOfAPageMovedWithinItsSite(): void
    {
        // Page 2 moves from below page 1 to below page 4, both of the first site
        $recorded = &$this->bindMoveRecorders([1]);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 4);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        self::assertContains('test_index:pages:3', $recorded['removed'], 'The subpage was decided and removed');
    }

    /**
     * Tests that the subpages of a hidden subpage that extends its state are not
     * decided with a moved tree, because they cannot be reached in the frontend,
     * while the hidden subpage itself is decided and its document goes.
     */
    #[Test]
    public function invokeLeavesThePagesBelowAHiddenPageThatExtendsItsStateOutOfTheDecision(): void
    {
        // Page 3 below the moved page 2 is hidden and extends that to its subpages
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->update('pages', ['hidden' => 1, 'extendToSubpages' => 1], ['uid' => 3]);
        $connection->insert('pages', ['uid' => 9, 'pid' => 3, 'title' => 'Below hidden', 'doktype' => 1]);

        $recorded = &$this->bindMoveRecorders([]);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 4);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        $decided = array_merge([], ...array_values($recorded['enqueued']));

        self::assertContains(3, $decided, 'The hidden page is decided');
        self::assertNotContains(9, $decided, 'The page below it is not');
    }

    /**
     * Tests that a site root below a moved page is left to its own site. Neither
     * it nor the pages below it are decided with the services of the site the
     * page moved to.
     */
    #[Test]
    public function invokeLeavesASiteRootBelowTheMovedPageToItsOwnSite(): void
    {
        $this->insertFormerSite();
        $this->insertPage(85, 3, 1);
        $this->insertPage(86, 85);

        $recorded = &$this->bindMoveRecorders([]);

        // Page 2 moves below the root page 10 of the other site, which is served by service 4
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 10], ['uid' => 2]);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 10);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        $seenByNewSite = $recorded['enqueued'][4] ?? [];

        self::assertContains(3, $seenByNewSite, 'The subpage is decided by the new site');
        self::assertNotContains(85, $seenByNewSite, 'The nested site root is left to its own site');
        self::assertNotContains(86, $seenByNewSite, 'So is everything below it');
    }

    /**
     * Tests that the subpages of a moved page are decided by the new site although
     * their rootlines are cached and still lead to the former site, because
     * DataHandler flushes the rootline cache only after the hooks ran. The tree
     * has a site root of its own below it, so the subpages have to be told apart.
     */
    #[Test]
    public function invokeDecidesTheSubpagesOfAMovedTreeWithCachedRootlines(): void
    {
        $this->insertFormerSite();
        $this->insertPage(85, 3, 1);
        $this->insertPage(86, 85);

        // A rootline cache that really stores, unlike the one of the test framework
        $this->get(CacheManager::class)->setCacheConfigurations(
            [
                'rootline' => [
                    'frontend' => VariableFrontend::class,
                    'backend'  => TransientMemoryBackend::class,
                    'options'  => [],
                    'groups'   => ['pages'],
                ],
            ]
        );

        // The rootline of subpage 3 is cached and leads to the first site
        self::assertSame(1, (new PageRepository($this->getConnectionPool()))->getRootPageId(3));

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 10], ['uid' => 2]);

        $recorded = &$this->bindMoveRecorders([]);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 10);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        $seenByNewSite = $recorded['enqueued'][4] ?? [];

        self::assertContains(3, $seenByNewSite, 'The subpage is decided by the new site');
        self::assertNotContains(85, $seenByNewSite, 'The nested site root is left to its own site');
        self::assertNotContains(86, $seenByNewSite, 'So is everything below it');
    }

    /**
     * Tests that a site root that is moved keeps its own site, so its tree is not
     * decided again with the services of the site it was placed in.
     */
    #[Test]
    public function invokeDoesNotDecideTheTreeOfAMovedSiteRoot(): void
    {
        $this->insertFormerSite();
        $this->insertPage(85, 3, 1);
        $this->insertPage(86, 85);

        $recorded = &$this->bindMoveRecorders([]);

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 10], ['uid' => 85]);

        $event = new DataHandlerRecordMoveEvent('pages', 85, 10);
        $event->setPreviousPid(3);

        ($this->subject)($event);

        $decidedByAnyService = array_merge([], ...array_values($recorded['enqueued']));

        self::assertContains(85, $recorded['enqueued'][1] ?? [], 'The moved site root itself is decided by its own service');
        self::assertNotContains(86, $decidedByAnyService, 'The page below the moved site root is not decided again');
        self::assertNotContains(
            85,
            $recorded['enqueued'][4] ?? [],
            'A moved site root is not decided by the site it was placed in'
        );
    }

    /**
     * Tests that the subpages and all content elements of a page that moved to
     * another site are removed from the index of the former site as well,
     * because they belong to the other site as soon as their ancestor moved.
     */
    #[Test]
    public function invokeRemovesTheSubtreeOfAMovedPageFromTheIndexOfTheFormerSite(): void
    {
        $this->insertFormerSite();

        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 5,
                    'pid'             => 10,
                    'title'           => 'Former Site Content Indexer',
                    'type'            => 'tt_content',
                    'search_engine'   => 2,
                    'deleted'         => 0,
                    'pages_recursive' => '10',
                ]
            );

        $rejectingPageIndexer = $this->createMock(IndexerInterface::class);
        $rejectingPageIndexer->method('dequeueOne')->willReturn($rejectingPageIndexer);
        $rejectingPageIndexer->method('dequeueMultiple')->willReturn($rejectingPageIndexer);
        $rejectingPageIndexer->method('enqueueOne')->willReturn(0);
        $rejectingPageIndexer->method('enqueueEligible')->willReturnCallback(static fn (): array => []);

        $acceptingPageIndexer = $this->createMock(IndexerInterface::class);
        $acceptingPageIndexer->method('dequeueOne')->willReturn($acceptingPageIndexer);
        $acceptingPageIndexer->method('dequeueMultiple')->willReturn($acceptingPageIndexer);
        $acceptingPageIndexer->method('enqueueOne')->willReturn(1);
        $acceptingPageIndexer->method('enqueueEligible')->willReturnCallback(static fn (array $uids): array => $uids);

        // The content service of the new site accepts the elements, the one of the former site does not
        $requeued                = [];
        $acceptingContentIndexer = $this->createMock(ContentIndexer::class);
        $acceptingContentIndexer->method('getTable')->willReturn('tt_content');
        $acceptingContentIndexer->method('dequeueMultiple')->willReturn($acceptingContentIndexer);
        $acceptingContentIndexer
            ->method('enqueueEligible')
            ->willReturnCallback(
                static function (array $uids) use (&$requeued): array {
                    $requeued = [...$requeued, ...$uids];

                    return $uids;
                }
            );

        $rejectingContentIndexer = $this->createMock(ContentIndexer::class);
        $rejectingContentIndexer->method('getTable')->willReturn('tt_content');
        $rejectingContentIndexer->method('dequeueMultiple')->willReturn($rejectingContentIndexer);
        $rejectingContentIndexer->method('enqueueEligible')->willReturn([]);

        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                static fn (IndexingService $service): IndexerInterface => match ((int) $service->getUid()) {
                    4       => $rejectingPageIndexer,
                    2       => $acceptingContentIndexer,
                    5       => $rejectingContentIndexer,
                    default => $acceptingPageIndexer,
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);

        $indexName    = '';
        $removed      = [];
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturnCallback(
                static function (string $name) use (&$indexName, $searchEngine): SearchEngineInterface {
                    $indexName = $name;

                    return $searchEngine;
                }
            );
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$indexName, &$removed): void {
                    foreach ($uids as $uid) {
                        $removed[] = $indexName . ':' . $table . ':' . $uid;
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        // Page 2 with the content elements 1, 2, 4 and 5 has subpage 3 with content element 3
        $event = new DataHandlerRecordMoveEvent('pages', 2, 1);
        $event->setPreviousPid(10);

        ($this->subject)($event);

        $formerIndex = array_values(
            array_filter(
                $removed,
                static fn (string $entry): bool => str_starts_with($entry, 'former_index:')
            )
        );

        sort($formerIndex);

        sort($requeued);

        self::assertSame([1, 2, 3, 4, 5], array_values(array_unique($requeued)), 'The content elements are queued again for the new site');

        self::assertSame(
            [
                'former_index:pages:2',
                'former_index:pages:3',
                'former_index:tt_content:1',
                'former_index:tt_content:2',
                'former_index:tt_content:3',
                'former_index:tt_content:4',
                'former_index:tt_content:5',
            ],
            $formerIndex
        );
    }

    /**
     * Tests that a record that moved between two sites that write to the same
     * index is removed from it when neither site accepts it. Each site alone
     * would keep the document, because the service of the other site writes to
     * the same index.
     */
    #[Test]
    public function invokeRemovesTheDocumentWhenNeitherSiteOfASharedIndexAcceptsTheRecord(): void
    {
        $connectionPool = $this->getConnectionPool();

        $connectionPool
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => 10,
                    'pid'         => 0,
                    'title'       => 'Former Site Root',
                    'is_siteroot' => 1,
                    'doktype'     => 1,
                    'deleted'     => 0,
                    'hidden'      => 0,
                    'no_search'   => 0,
                ]
            );

        // The service of the former site writes to the same search engine as the first one
        $connectionPool
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 4,
                    'pid'             => 10,
                    'title'           => 'Former Site Page Indexer',
                    'type'            => 'pages',
                    'search_engine'   => 1,
                    'deleted'         => 0,
                    'pages_recursive' => '10',
                    'pages_doktype'   => '1',
                ]
            );

        $this->indexerMock
            ->method('dequeueOne')
            ->willReturn($this->indexerMock);
        $this->indexerMock
            ->method('enqueueEligible')
            ->willReturn([]);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [4]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $event = new DataHandlerRecordMoveEvent('pages', 4, 1);
        $event->setPreviousPid(10);

        ($this->subject)($event);
    }

    /**
     * Tests that the listener does nothing when the target page ID
     * equals the previous page ID, indicating no actual move occurred.
     */
    #[Test]
    public function invokeDoesNothingWhenTargetEqualsPrevious(): void
    {
        $this->indexerFactoryMock
            ->expects(self::never())
            ->method('makeInstanceByType');

        $event = new DataHandlerRecordMoveEvent('pages', 2, 1);
        $event->setPreviousPid(1);

        ($this->subject)($event);
    }

    /**
     * Tests that the listener handles a move event correctly when the
     * previous page ID is null, treating it as a valid move operation.
     */
    #[Test]
    public function invokeHandlesMoveWithNullPreviousPid(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->method('dequeueOne')
            ->willReturn($this->indexerMock);

        // The move is processed, so the record is queued again
        $this->indexerMock
            ->expects(self::atLeastOnce())
            ->method('enqueueEligible')
            ->with([4])
            ->willReturnCallback(static fn (array $uids): array => $uids);

        // previousPid is null by default, targetPid differs from null
        $event = new DataHandlerRecordMoveEvent('pages', 4, 3);

        ($this->subject)($event);
    }

    /**
     * Tests that the listener asks for the removal of the document when no
     * indexer accepts the moved record any more, which is the case of a page
     * that is moved out of the pages an indexing service covers.
     */
    #[Test]
    public function invokeRemovesTheDocumentOfAMovedRecordNoIndexerAccepts(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->method('dequeueOne')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->method('enqueueEligible')
            ->willReturn([]);

        $searchEngineMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineMock
            ->method('withIndexName')
            ->willReturn($searchEngineMock);
        $searchEngineMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [4]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineMock);

        $event = new DataHandlerRecordMoveEvent('pages', 4, 3);
        $event->setPreviousPid(1);

        ($this->subject)($event);
    }

    /**
     * Binds indexers that accept every record and a search engine that records
     * the documents it removes.
     *
     * The page indexers record the pages they decide or queue again, and the
     * content indexers record the content elements that are queued and every
     * call that queues a list of them.
     *
     * @param int[] $rejectingContentServices The UIDs of the content services that accept no element
     *
     * @return array{removed: string[], contentQueued: int[], contentLists: array<int, int[]>, pagesDecided: int[]} Filled while the test runs
     */
    private function &bindTreeRecorders(array $rejectingContentServices = []): array
    {
        $recorded = [
            'removed'       => [],
            'contentQueued' => [],
            'contentLists'  => [],
            'pagesDecided'  => [],
        ];

        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                function (IndexingService $service) use (&$recorded, $rejectingContentServices): IndexerInterface {
                    if ($service->getType() === 'tt_content') {
                        $rejects = in_array((int) $service->getUid(), $rejectingContentServices, true);

                        $indexer = $this->createMock(ContentIndexer::class);
                        $indexer->method('getTable')->willReturn('tt_content');
                        $indexer->method('dequeueOne')->willReturn($indexer);
                        $indexer->method('dequeueMultiple')->willReturn($indexer);
                        $indexer
                            ->method('enqueueOne')
                            ->willReturnCallback(
                                static function (int $uid) use (&$recorded): int {
                                    $recorded['contentQueued'][] = $uid;

                                    return 1;
                                }
                            );
                        $indexer
                            ->method('enqueueEligible')
                            ->willReturnCallback(
                                static function (array $uids) use (&$recorded, $rejects): array {
                                    if ($rejects) {
                                        return [];
                                    }

                                    $recorded['contentQueued'] = [...$recorded['contentQueued'], ...$uids];

                                    return $uids;
                                }
                            );
                        $indexer
                            ->method('enqueueEligible')
                            ->willReturnCallback(
                                static function (array $uids) use (&$recorded): int {
                                    $recorded['contentLists'][] = $uids;
                                    $recorded['contentQueued']  = [...$recorded['contentQueued'], ...$uids];

                                    return count($uids);
                                }
                            );

                        return $indexer;
                    }

                    $indexer = $this->createMock(PageIndexer::class);
                    $indexer->method('getTable')->willReturn('pages');
                    $indexer->method('isIncludeContentElements')->willReturn(true);
                    $indexer->method('dequeueOne')->willReturn($indexer);
                    $indexer->method('dequeueMultiple')->willReturn($indexer);
                    $indexer
                        ->method('enqueueOne')
                        ->willReturnCallback(
                            static function (int $uid) use (&$recorded): int {
                                $recorded['pagesDecided'][] = $uid;

                                return 1;
                            }
                        );
                    $indexer
                        ->method('enqueueEligible')
                        ->willReturnCallback(
                            static function (array $uids) use (&$recorded): array {
                                $recorded['pagesDecided'] = [...$recorded['pagesDecided'], ...$uids];

                                return $uids;
                            }
                        );

                    return $indexer;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);

        $indexName    = '';
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturnCallback(
                static function (string $name) use (&$indexName, $searchEngine): SearchEngineInterface {
                    $indexName = $name;

                    return $searchEngine;
                }
            );
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$indexName, &$recorded): void {
                    foreach ($uids as $uid) {
                        $recorded['removed'][] = $indexName . ':' . $table . ':' . $uid;
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        return $recorded;
    }

    /**
     * Inserts a content element.
     *
     * @param int $uid           The UID of the content element
     * @param int $pid           The UID of the page it is stored on
     * @param int $translationOf The UID of the element this one translates, 0 for an original
     *
     * @return void
     */
    private function insertContentElement(
        int $uid,
        int $pid,
        int $translationOf = 0,
    ): void {
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => $pid,
                    'header'           => 'Content ' . $uid,
                    'CType'            => 'text',
                    'deleted'          => 0,
                    'hidden'           => 0,
                    'sys_language_uid' => ($translationOf > 0) ? 1 : 0,
                    'l18n_parent'      => $translationOf,
                ]
            );
    }

    /**
     * Adds an indexing service for content elements.
     *
     * @param int $uid             The UID of the indexing service
     * @param int $pid             The page the service is stored on, which decides its site
     * @param int $searchEngineUid The UID of the search engine the service writes to
     *
     * @return void
     */
    private function insertContentIndexingService(
        int $uid,
        int $pid,
        int $searchEngineUid,
    ): void {
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => $uid,
                    'pid'             => $pid,
                    'title'           => 'Content Indexer ' . $uid,
                    'type'            => 'tt_content',
                    'search_engine'   => $searchEngineUid,
                    'deleted'         => 0,
                    'pages_recursive' => '1',
                ]
            );
    }

    /**
     * Tests that a page that moves within its site, and stays in the page tree
     * that the content service selects, takes no document of its content
     * elements out of the index. The content elements are queued again.
     */
    #[Test]
    public function invokeKeepsTheDocumentsOfTheContentElementsOfAPageMovedWithinItsSite(): void
    {
        $recorded = &$this->bindTreeRecorders();

        // Page 2 with the content elements 1, 2, 4 and 5 and the subpage 3 with element 3
        $event = new DataHandlerRecordMoveEvent('pages', 2, 4);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        $removedContent = array_values(
            array_filter(
                $recorded['removed'],
                static fn (string $entry): bool => str_contains($entry, ':tt_content:')
            )
        );

        $queued = array_values(array_unique($recorded['contentQueued']));

        sort($queued);

        self::assertSame([], $removedContent, 'No document of a content element is removed');
        self::assertSame([1, 2, 3, 4, 5], $queued, 'The content elements are queued again');
    }

    /**
     * Tests that the documents of the content elements of a page that moves
     * within its site are removed when no content service accepts the elements
     * any more, for example because the page left the selected page tree. The
     * page documents are decided the same way.
     */
    #[Test]
    public function invokeRemovesTheDocumentsOfTheContentElementsOfAPageThatLeavesTheSelectionOfAContentService(): void
    {
        // The only content service of the site rejects every element after the move
        $recorded = &$this->bindTreeRecorders([2]);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 4);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        $removedContent = array_values(
            array_filter(
                $recorded['removed'],
                static fn (string $entry): bool => str_contains($entry, ':tt_content:')
            )
        );

        sort($removedContent);

        self::assertSame(
            [
                'test_index:tt_content:1',
                'test_index:tt_content:2',
                'test_index:tt_content:3',
                'test_index:tt_content:4',
                'test_index:tt_content:5',
            ],
            $removedContent
        );
    }

    /**
     * Tests that the translation of a subpage is not decided with the subpages of
     * a moved tree, because no indexer accepts a translation of a page and the
     * decision would only send removals for documents that never existed.
     */
    #[Test]
    public function invokeDoesNotDecideTheTranslationsOfSubpages(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'              => 21,
                    'pid'              => 2,
                    'title'            => 'Translation of page 3',
                    'doktype'          => 1,
                    'sys_language_uid' => 1,
                    'l10n_parent'      => 3,
                ]
            );

        $recorded = &$this->bindTreeRecorders();

        $event = new DataHandlerRecordMoveEvent('pages', 2, 4);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        self::assertContains(3, $recorded['pagesDecided'], 'The subpage is decided');
        self::assertNotContains(21, $recorded['pagesDecided'], 'Its translation is not');
    }

    /**
     * Tests that the translations of a content element that moves to another
     * site are decided with the services of the former site as well, so their
     * documents leave the index of the former site.
     */
    #[Test]
    public function invokeDecidesTheTranslationsOfAContentElementMovedToAnotherSiteWithTheFormerSite(): void
    {
        $this->insertFormerSite();
        $this->insertContentIndexingService(5, 10, 2);
        $this->insertContentElement(20, 2, 1);

        // The content service of the former site rejects the element and its translation
        $recorded = &$this->bindTreeRecorders([5]);

        // Content element 1 moves from page 2 to page 3, its translation 20 moves with it
        $event = new DataHandlerRecordMoveEvent('tt_content', 1, 3);
        $event->setPreviousPid(10);

        ($this->subject)($event);

        self::assertContains('former_index:tt_content:20', $recorded['removed']);
    }

    /**
     * Tests that a page that moves to another site takes the documents of its
     * content elements out of the index of the former site.
     */
    #[Test]
    public function invokeRemovesTheDocumentsOfTheContentElementsOfAPageMovedToAnotherSite(): void
    {
        $this->insertFormerSite();
        $this->insertContentIndexingService(5, 10, 2);

        // The content service of the former site no longer selects the moved pages
        $recorded = &$this->bindTreeRecorders([5]);

        // Page 2 leaves the site of the root page 10 for the first site
        $event = new DataHandlerRecordMoveEvent('pages', 2, 1);
        $event->setPreviousPid(10);

        ($this->subject)($event);

        $removedContent = array_values(
            array_filter(
                $recorded['removed'],
                static fn (string $entry): bool => str_starts_with($entry, 'former_index:tt_content:')
            )
        );

        sort($removedContent);

        self::assertSame(
            [
                'former_index:tt_content:1',
                'former_index:tt_content:2',
                'former_index:tt_content:3',
                'former_index:tt_content:4',
                'former_index:tt_content:5',
            ],
            $removedContent
        );
    }

    /**
     * Tests that a content element below a site root that lies in the moved
     * tree is neither removed from the index of the former site nor queued
     * again, because it keeps its own site together with its page.
     */
    #[Test]
    public function invokeLeavesTheContentElementsBelowANestedSiteRootAlone(): void
    {
        $this->insertFormerSite();
        $this->insertPage(85, 3, 1);
        $this->insertPage(86, 85);
        $this->insertContentElement(50, 86);

        // The content service of the former site rejects the elements of the moved tree
        $recorded = &$this->bindTreeRecorders([2]);

        // Page 2 moves below the root page 10, with the nested site root 85 below it
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 10], ['uid' => 2]);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 10);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        self::assertNotContains(85, $recorded['pagesDecided'], 'The nested site root is not decided');
        self::assertNotContains(86, $recorded['pagesDecided'], 'Neither is the page below it');
        self::assertNotContains('test_index:tt_content:50', $recorded['removed'], 'The document is not removed');
        self::assertNotContains(50, $recorded['contentQueued'], 'The content element is not queued again');
        self::assertContains('test_index:tt_content:3', $recorded['removed'], 'The content of the moved tree is');
    }

    /**
     * Tests that a moved content element decides its translations as well,
     * because DataHandler moves them without a command of their own, and that
     * the page that received the element is queued again next to the former one.
     */
    #[Test]
    public function invokeDecidesTheTranslationsOfAMovedContentElementAndRequeuesTheTargetPage(): void
    {
        $this->insertContentElement(20, 2, 1);

        $recorded = &$this->bindTreeRecorders();

        // Content element 1 moves from page 2 to page 3, both of the first site
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['pid' => 3], ['uid' => 1]);

        $event = new DataHandlerRecordMoveEvent('tt_content', 1, 3);
        $event->setPreviousPid(2);

        ($this->subject)($event);

        $queued = array_values(array_unique($recorded['contentQueued']));
        $pages  = array_values(array_unique($recorded['pagesDecided']));

        sort($queued);
        sort($pages);

        self::assertSame([1, 20], $queued, 'The element and its translation are decided');
        self::assertSame([2, 3], $pages, 'The former and the new page are queued again');
    }

    /**
     * Tests that the child elements of a moved content element are decided as
     * well, together with their translations, because DataHandler moves them
     * with the element and gives them no command of their own.
     */
    #[Test]
    public function invokeDecidesTheInlineChildrenOfAMovedContentElement(): void
    {
        $this->addInlineChildRelation();

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Content element 1 has the child 80, whose translation is 81. They move to page 3 with it
        foreach ([[80, 1, 0, 0], [81, 0, 80, 1]] as [$uid, $parent, $translationOf, $language]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 3,
                    'header'           => 'Child',
                    'CType'            => 'text',
                    'sys_language_uid' => $language,
                    'l18n_parent'      => $translationOf,
                    'tx_test_parent'   => $parent,
                ]
            );
        }

        $recorded = &$this->bindTreeRecorders();

        $connection->update('tt_content', ['pid' => 3], ['uid' => 1]);

        $event = new DataHandlerRecordMoveEvent('tt_content', 1, 3);
        $event->setPreviousPid(2);

        ($this->subject)($event);

        $queued = array_values(array_unique($recorded['contentQueued']));

        sort($queued);

        self::assertSame([1, 80, 81], $queued, 'The element, its child and the translation of the child are decided');
    }

    /**
     * Tests that the inline children of the translation of a moved content
     * element are decided as well, because DataHandler moves them with it.
     */
    #[Test]
    public function invokeDecidesTheInlineChildrenOfTheTranslationOfAMovedContentElement(): void
    {
        $this->addInlineChildRelation();

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Element 1 has the translation 72, and that translation has the child 82
        foreach ([[72, 0, 1, 1], [82, 72, 0, 1]] as [$uid, $parent, $translationOf, $language]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 3,
                    'header'           => 'Related',
                    'CType'            => 'text',
                    'sys_language_uid' => $language,
                    'l18n_parent'      => $translationOf,
                    'tx_test_parent'   => $parent,
                ]
            );
        }

        $recorded = &$this->bindTreeRecorders();

        $connection->update('tt_content', ['pid' => 3], ['uid' => 1]);

        $event = new DataHandlerRecordMoveEvent('tt_content', 1, 3);
        $event->setPreviousPid(2);

        ($this->subject)($event);

        $queued = array_values(array_unique($recorded['contentQueued']));

        sort($queued);

        self::assertSame([1, 72, 82], $queued);
    }

    /**
     * Tests that a page that comes from outside of any site removes no document
     * of its content elements, because there is no index of a former site.
     */
    #[Test]
    public function invokeRemovesNoDocumentsForAPageThatComesFromOutsideOfAnySite(): void
    {
        $this->insertPage(20, 0);

        // A content indexing service that is stored outside of any site, whose root page is 0
        $this->insertContentIndexingService(6, 20, 1);

        $recorded = &$this->bindTreeRecorders();

        $event = new DataHandlerRecordMoveEvent('pages', 2, 4);
        $event->setPreviousPid(20);

        ($this->subject)($event);

        $removedContent = array_values(
            array_filter(
                $recorded['removed'],
                static fn (string $entry): bool => str_contains($entry, ':tt_content:')
            )
        );

        self::assertSame([], $removedContent);
    }

    /**
     * Tests that a content element moved without a known former page is queued
     * itself and nothing more, because there is no former page to queue again.
     */
    #[Test]
    public function invokeQueuesOnlyTheContentElementWhenTheFormerPageIsUnknown(): void
    {
        $recorded = &$this->bindTreeRecorders();

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['pid' => 3], ['uid' => 1]);

        ($this->subject)(new DataHandlerRecordMoveEvent('tt_content', 1, 3));

        self::assertSame([1], array_values(array_unique($recorded['contentQueued'])));
        self::assertSame([], $recorded['pagesDecided'], 'No page is queued again');
    }

    /**
     * Tests that the move of a page does not queue its former and its new parent
     * again, which is what the move of a content element does for its pages.
     */
    #[Test]
    public function invokeDoesNotQueueTheParentsOfAMovedPageAgain(): void
    {
        $recorded = &$this->bindTreeRecorders();

        // Page 2 moves from below page 1 to below page 4
        $event = new DataHandlerRecordMoveEvent('pages', 2, 4);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        self::assertNotContains(1, $recorded['pagesDecided'], 'The former parent is not queued');
        self::assertNotContains(4, $recorded['pagesDecided'], 'The new parent is not queued');
    }

    /**
     * Tests that the former page of a content element that comes from outside of
     * any site is queued again with the services of the site it moved to.
     */
    #[Test]
    public function invokeRequeuesAFormerPageOutsideOfAnySiteWithTheNewSite(): void
    {
        $this->insertPage(20, 0);

        $recorded = &$this->bindTreeRecorders();

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['pid' => 3], ['uid' => 1]);

        $event = new DataHandlerRecordMoveEvent('tt_content', 1, 3);
        $event->setPreviousPid(20);

        ($this->subject)($event);

        self::assertContains(20, $recorded['pagesDecided'], 'The former page is queued again');
        self::assertContains(3, $recorded['pagesDecided'], 'So is the page that received the element');
    }

    /**
     * Tests that a moved page without content elements, in a tree without any,
     * does not ask for the content elements to be queued with an empty list,
     * which the queue would read as every content element.
     */
    #[Test]
    public function invokeDoesNotQueueAnEmptyListOfContentElementsForATreeWithoutAny(): void
    {
        $recorded = &$this->bindTreeRecorders();

        // Page 4 has no subpages and no content elements
        $event = new DataHandlerRecordMoveEvent('pages', 4, 3);
        $event->setPreviousPid(1);

        ($this->subject)($event);

        self::assertSame([], $recorded['contentLists']);
        self::assertSame([], $recorded['contentQueued']);
    }
}
