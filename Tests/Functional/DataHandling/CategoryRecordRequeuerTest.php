<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\DataHandling;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\CategoryRecordRequeuer;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;

use function count;

/**
 * Functional tests for CategoryRecordRequeuer.
 *
 * Uses real DB queries for the category assignments, the category tree, the
 * records and the root page resolution. Mocks IndexerFactory, so the queue
 * operations of each indexing service are observable without a search engine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(CategoryRecordRequeuer::class)]
final class CategoryRecordRequeuerTest extends AbstractFunctionalTestCase
{
    /**
     * The indexer of the page indexing service in the first site (service uid 1).
     */
    private MockObject&IndexerInterface $mainSitePageIndexerMock;

    /**
     * The indexer of the page indexing service in the second site (service uid 5).
     */
    private MockObject&IndexerInterface $secondSitePageIndexerMock;

    /**
     * The indexer of the file indexing service (service uid 6).
     */
    private MockObject&IndexerInterface $fileIndexerMock;

    /**
     * The event dispatcher handing out the CollectCategoryRecordsEvent.
     */
    private MockObject&EventDispatcherInterface $eventDispatcherMock;

    /**
     * The record handler wired with the mocked indexer factory.
     */
    private RecordHandler $recordHandler;

    /**
     * The requeuer under test.
     */
    private CategoryRecordRequeuer $subject;

    /**
     * Imports the fixtures and wires the requeuer with real repositories.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_second_root.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_tree.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_record_mm_requeue.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_file_metadata.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice_requeue.csv');

        $this->mainSitePageIndexerMock   = $this->createMock(IndexerInterface::class);
        $this->secondSitePageIndexerMock = $this->createMock(IndexerInterface::class);
        $this->fileIndexerMock           = $this->createMock(IndexerInterface::class);

        // Like the full queue rebuild, the requeue leaves out pages below a hidden
        // subpage of a recursively selected page tree
        foreach ([$this->mainSitePageIndexerMock, $this->secondSitePageIndexerMock, $this->fileIndexerMock] as $indexerMock) {
            $indexerMock
                ->method('withExcludeHiddenPages')
                ->with(true)
                ->willReturnSelf();
        }

        $indexersByServiceUid = [
            1 => $this->mainSitePageIndexerMock,
            5 => $this->secondSitePageIndexerMock,
            6 => $this->fileIndexerMock,
        ];

        // The mocked factory hands out a fresh prototype per call, its
        // withIndexingService() returns the indexer mock of the given service.
        $indexerFactoryMock = $this->createMock(IndexerFactory::class);
        $indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturnCallback(
                function (string $type) use ($indexersByServiceUid): IndexerInterface {
                    $prototype = $this->createMock(IndexerInterface::class);
                    $prototype
                        ->method('withIndexingService')
                        ->willReturnCallback(
                            static fn (IndexingService $indexingService): IndexerInterface => $indexersByServiceUid[(int) $indexingService->getUid()],
                        );

                    return $prototype;
                },
            );

        $this->eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);

        $connectionPool = $this->getConnectionPool();
        $pageRepository = new PageRepository($connectionPool);

        $this->recordHandler = new RecordHandler(
            $this->createMock(SearchEngineFactory::class),
            $indexerFactoryMock,
            $pageRepository,
            $this->get(IndexingServiceRepository::class),
            new ContentRepository($connectionPool),
        );

        $this->subject = $this->createSubject($pageRepository);
    }

    /**
     * Creates the requeuer with the given repository for the root page resolution.
     *
     * @param PageRepositoryInterface $pageRepository The repository resolving root pages
     *
     * @return CategoryRecordRequeuer The requeuer
     */
    private function createSubject(PageRepositoryInterface $pageRepository): CategoryRecordRequeuer
    {
        $connectionPool = $this->getConnectionPool();

        return new CategoryRecordRequeuer(
            new CategoryRepository($connectionPool),
            $this->recordHandler,
            $pageRepository,
            $this->eventDispatcherMock,
            $connectionPool,
        );
    }

    /**
     * Lets the mocked event dispatcher hand the collect event back unchanged.
     *
     * @return void
     */
    private function dispatchEventsUnchanged(): void
    {
        $this->eventDispatcherMock
            ->method('dispatch')
            ->willReturnArgument(0);
    }

    /**
     * Expects the given indexer to requeue exactly the given records, removing
     * them from the queue first so no duplicate queue item can occur.
     *
     * @param MockObject&IndexerInterface $indexerMock The indexer expected to requeue
     * @param list<int>                   $recordUids  The UIDs expected to be requeued
     *
     * @return void
     */
    private function expectRequeue(
        MockObject&IndexerInterface $indexerMock,
        array $recordUids,
    ): void {
        $indexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with($recordUids)
            ->willReturnSelf();
        $indexerMock
            ->expects(self::once())
            ->method('enqueueMultiple')
            ->with($recordUids)
            ->willReturn(count($recordUids));
    }

    /**
     * Expects the given indexer not to be used at all.
     *
     * @param MockObject&IndexerInterface $indexerMock The indexer expected to stay untouched
     *
     * @return void
     */
    private function expectNoRequeue(MockObject&IndexerInterface $indexerMock): void
    {
        $indexerMock->expects(self::never())->method('dequeueMultiple');
        $indexerMock->expects(self::never())->method('enqueueMultiple');
    }

    /**
     * Assignments to a table TYPO3 no longer knows, e.g. left behind by a
     * removed extension, are skipped instead of failing the category save.
     */
    #[Test]
    public function skipsAssignmentsToTablesWithoutTca(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_record_mm_missing_table.csv');

        $this->dispatchEventsUnchanged();

        $this->expectRequeue($this->mainSitePageIndexerMock, [2]);
        $this->expectRequeue($this->secondSitePageIndexerMock, [41]);
        $this->expectRequeue($this->fileIndexerMock, [10]);

        $this->subject->requeue(7);
    }

    /**
     * Pages are requeued through the indexer of the site they belong to, and
     * files through the file indexer, each with only their own records.
     */
    #[Test]
    public function requeuesEachRecordThroughTheIndexerOfItsSiteAndTable(): void
    {
        $this->dispatchEventsUnchanged();

        $this->expectRequeue($this->mainSitePageIndexerMock, [2]);
        $this->expectRequeue($this->secondSitePageIndexerMock, [41]);
        $this->expectRequeue($this->fileIndexerMock, [10]);

        $this->subject->requeue(7);
    }

    /**
     * A site root page assigned to the category is requeued through the
     * indexer of its own site, since it is the root of its page tree.
     */
    #[Test]
    public function requeuesASiteRootPageThroughTheIndexerOfItsOwnSite(): void
    {
        $this->dispatchEventsUnchanged();

        $this->expectNoRequeue($this->mainSitePageIndexerMock);
        $this->expectRequeue($this->secondSitePageIndexerMock, [40]);
        $this->expectNoRequeue($this->fileIndexerMock);

        $this->subject->requeue(8);
    }

    /**
     * Records remembered before the save are requeued even when the save
     * removed their assignment, e.g. in the category's own items field.
     */
    #[Test]
    public function requeuesRecordsWhoseAssignmentWasRemovedByTheSave(): void
    {
        $this->dispatchEventsUnchanged();

        $this->subject->rememberAssignedRecords(7);

        $this->getConnectionPool()
            ->getConnectionForTable('sys_category_record_mm')
            ->delete(
                'sys_category_record_mm',
                [
                    'uid_local'   => 7,
                    'uid_foreign' => 41,
                ],
            );

        $this->expectRequeue($this->mainSitePageIndexerMock, [2]);
        $this->expectRequeue($this->secondSitePageIndexerMock, [41]);
        $this->expectRequeue($this->fileIndexerMock, [10]);

        $this->subject->requeue(7);
    }

    /**
     * Remembered records are only used for the next requeue of that category,
     * a later save does not requeue them again.
     */
    #[Test]
    public function forgetsRememberedRecordsAfterTheRequeue(): void
    {
        $this->dispatchEventsUnchanged();

        $this->subject->rememberAssignedRecords(7);

        $this->getConnectionPool()
            ->getConnectionForTable('sys_category_record_mm')
            ->delete(
                'sys_category_record_mm',
                [
                    'uid_local' => 7,
                ],
            );

        $this->mainSitePageIndexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->willReturnSelf();

        $this->subject->requeue(7);
        $this->subject->requeue(7);
    }

    /**
     * Records remembered before a save that DataHandler then refused are
     * dropped, so a later requeue of the category does not pick them up.
     */
    #[Test]
    public function forgetsRememberedRecordsOfARefusedSave(): void
    {
        $this->dispatchEventsUnchanged();

        $this->subject->rememberAssignedRecords(7);
        $this->subject->forgetRememberedRecords(7);

        $this->getConnectionPool()
            ->getConnectionForTable('sys_category_record_mm')
            ->delete(
                'sys_category_record_mm',
                [
                    'uid_local' => 7,
                ],
            );

        $this->expectNoRequeue($this->mainSitePageIndexerMock);
        $this->expectNoRequeue($this->secondSitePageIndexerMock);
        $this->expectNoRequeue($this->fileIndexerMock);

        $this->subject->requeue(7);
    }

    /**
     * The records of the visible subcategories are requeued too, since documents may
     * carry the title path of their categories, which includes the saved
     * category's title.
     */
    #[Test]
    public function requeuesTheRecordsOfSubcategories(): void
    {
        $this->dispatchEventsUnchanged();

        $this->expectRequeue($this->mainSitePageIndexerMock, [3]);
        $this->expectNoRequeue($this->secondSitePageIndexerMock);
        $this->expectNoRequeue($this->fileIndexerMock);

        $this->subject->requeue(20);
    }

    /**
     * Records added by a CollectCategoryRecordsEvent listener are requeued
     * together with the category's own assignments.
     */
    #[Test]
    public function requeuesRecordsAddedThroughTheCollectEvent(): void
    {
        $this->eventDispatcherMock
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(
                static function (CollectCategoryRecordsEvent $event): CollectCategoryRecordsEvent {
                    self::assertSame(7, $event->getCategoryUid());

                    return $event->addRecordUids('pages', [3]);
                },
            );

        $this->expectRequeue($this->mainSitePageIndexerMock, [2, 3]);
        $this->expectRequeue($this->secondSitePageIndexerMock, [41]);
        $this->expectRequeue($this->fileIndexerMock, [10]);

        $this->subject->requeue(7);
    }

    /**
     * Records that no longer exist, or whose page tree cannot be resolved
     * because their parent page is gone, are left out instead of failing.
     */
    #[Test]
    public function leavesOutRecordsThatNoLongerExistOrCannotBeResolved(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_orphaned.csv');

        $this->eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static fn (CollectCategoryRecordsEvent $event): CollectCategoryRecordsEvent => $event->addRecordUids(
                    'pages',
                    [7, 999],
                ),
            );

        $this->expectRequeue($this->mainSitePageIndexerMock, [2]);
        $this->expectRequeue($this->secondSitePageIndexerMock, [41]);
        $this->expectRequeue($this->fileIndexerMock, [10]);

        $this->subject->requeue(7);
    }

    /**
     * Hidden records are requeued, the indexers decide themselves whether a
     * hidden record is indexed, while deleted records are left out. Records
     * below the same parent page share one root page resolution.
     */
    #[Test]
    public function requeuesHiddenButNotDeletedRecordsResolvingEachParentPageOnce(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_deleted.csv');

        $this->dispatchEventsUnchanged();

        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock
            ->expects(self::once())
            ->method('getRootPageId')
            ->with(1)
            ->willReturn(1);

        $this->expectRequeue($this->mainSitePageIndexerMock, [4, 6]);
        $this->expectNoRequeue($this->secondSitePageIndexerMock);
        $this->expectNoRequeue($this->fileIndexerMock);

        $this->createSubject($pageRepositoryMock)->requeue(9);
    }

    /**
     * A category without any assigned record does not touch an indexer.
     */
    #[Test]
    public function doesNothingForACategoryWithoutAssignedRecords(): void
    {
        $this->dispatchEventsUnchanged();

        $this->expectNoRequeue($this->mainSitePageIndexerMock);
        $this->expectNoRequeue($this->secondSitePageIndexerMock);
        $this->expectNoRequeue($this->fileIndexerMock);

        $this->subject->requeue(99);
    }
}
