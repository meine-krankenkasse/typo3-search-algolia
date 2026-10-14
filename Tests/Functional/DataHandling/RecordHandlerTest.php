<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\DataHandling;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;

use function array_intersect;
use function array_values;
use function count;
use function range;
use function sort;

/**
 * Functional tests for RecordHandler.
 *
 * Tests root page resolution, indexer generator creation with real DB lookups,
 * queue update operations, and record deletion with mocked external services.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordHandler::class)]
final class RecordHandlerTest extends AbstractFunctionalTestCase
{
    private MockObject&IndexerFactory $indexerFactoryMock;

    private MockObject&SearchEngineFactory $searchEngineFactoryMock;

    private RecordHandler $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool    = $this->getConnectionPool();
        $pageRepository    = new PageRepository($connectionPool);
        $contentRepository = new ContentRepository($connectionPool);

        $this->indexerFactoryMock      = $this->createMock(IndexerFactory::class);
        $this->searchEngineFactoryMock = $this->createMock(SearchEngineFactory::class);

        // Subject with real IndexingServiceRepository (via DI/Extbase persistence)
        $this->subject = new RecordHandler(
            $this->searchEngineFactoryMock,
            $this->indexerFactoryMock,
            $pageRepository,
            $this->get(IndexingServiceRepository::class),
            $contentRepository,
        );
    }

    // -----------------------------------------------------------------------
    // getRecordRootPageId
    // -----------------------------------------------------------------------

    /**
     * Tests that getRecordRootPageId() returns the root page UID
     * for a record that is a direct child of the root page.
     */
    #[Test]
    public function getRecordRootPageIdReturnsRootForPageRecord(): void
    {
        $rootPageId = $this->subject->getRecordRootPageId(
            ['uid' => 2, 'pid' => 1],
            'pages',
            2
        );

        self::assertSame(1, $rootPageId);
    }

    /**
     * Tests that getRecordRootPageId() returns the root page UID
     * for a deeply nested page by traversing the page tree upward.
     */
    #[Test]
    public function getRecordRootPageIdReturnsRootForDeepPage(): void
    {
        $rootPageId = $this->subject->getRecordRootPageId(
            ['uid' => 3, 'pid' => 2],
            'pages',
            3
        );

        self::assertSame(1, $rootPageId);
    }

    /**
     * Tests that getRecordRootPageId() returns the root page UID
     * for a content element by resolving via its parent page ID.
     */
    #[Test]
    public function getRecordRootPageIdReturnsRootForContentElement(): void
    {
        // For non-pages tables, the pid is used to find the page
        $rootPageId = $this->subject->getRecordRootPageId(
            ['uid' => 1, 'pid' => 2],
            'tt_content',
            1
        );

        self::assertSame(1, $rootPageId);
    }

    /**
     * Tests that getRecordRootPageId() throws a PageNotFoundException
     * when the record's parent page does not exist in the database.
     */
    #[Test]
    public function getRecordRootPageIdThrowsExceptionForOrphanRecord(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->subject->getRecordRootPageId(
            ['uid' => 999, 'pid' => 888],
            'tt_content',
            999
        );
    }

    /**
     * Tests that getRecordRootPageId() uses the record UID itself
     * (not the pid) when the table is 'pages', returning the root page.
     */
    #[Test]
    public function getRecordRootPageIdUsesRecordUidForPages(): void
    {
        // For 'pages', the recordUid itself is passed to getRootPageId,
        // not the pid from the record
        $rootPageId = $this->subject->getRecordRootPageId(
            ['uid' => 1, 'pid' => 0],
            'pages',
            1
        );

        self::assertSame(1, $rootPageId);
    }

    // -----------------------------------------------------------------------
    // createIndexerGenerator
    // -----------------------------------------------------------------------

    /**
     * Tests that createIndexerGenerator() yields an indexer instance
     * when the root page ID matches an existing indexing service.
     */
    #[Test]
    public function createIndexerGeneratorYieldsIndexerForMatchingRootPage(): void
    {
        // Indexing service uid=1 lives on pid=1 (root page 1).
        // So requesting rootPageId=1 for table 'pages' should yield it.
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->with('pages')
            ->willReturn($indexerMock);

        $generator = $this->subject->createIndexerGenerator(1, 'pages');

        $yielded = [];

        foreach ($generator as $indexingService => $indexerInstance) {
            self::assertInstanceOf(IndexingService::class, $indexingService);
            $yielded[] = $indexerInstance;
        }

        self::assertCount(1, $yielded);
        self::assertSame($indexerMock, $yielded[0]);
    }

    /**
     * Tests that createIndexerGenerator() yields nothing when the
     * root page ID does not match any configured indexing service.
     */
    #[Test]
    public function createIndexerGeneratorYieldsNothingForNonMatchingRootPage(): void
    {
        // Indexing service uid=1 lives on pid=1 (root page 1).
        // Requesting rootPageId=999 should yield nothing.
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $generator = $this->subject->createIndexerGenerator(999, 'pages');
        $count     = 0;

        foreach ($generator as $indexerInstance) {
            ++$count;
        }

        self::assertSame(0, $count);
    }

    /**
     * Tests that createIndexerGenerator() yields nothing when the
     * indexer factory returns null for the given table type.
     */
    #[Test]
    public function createIndexerGeneratorYieldsNothingWhenFactoryReturnsNull(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn(null);

        $generator = $this->subject->createIndexerGenerator(1, 'pages');
        $count     = 0;

        foreach ($generator as $indexerInstance) {
            ++$count;
        }

        self::assertSame(0, $count);
    }

    // -----------------------------------------------------------------------
    // updateRecordsInQueue for a single record
    // -----------------------------------------------------------------------

    /**
     * Tests that updateRecordsInQueue() dequeues and then re-enqueues the
     * record for all matching indexers on the given root page.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordDequeuesAndEnqueuesRecord(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);

        $indexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([2])
            ->willReturn($indexerMock);

        $indexerMock
            ->expects(self::once())
            ->method('enqueueEligible')
            ->with([2])
            ->willReturnCallback(static fn (array $recordUids): array => $recordUids);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() removes the document of a record from
     * the search index when its indexer does not accept the record any more,
     * for example a file that was moved out of the indexed folder.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordRemovesDocumentWhenRecordIsNotEligible(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);
        $indexerMock
            ->method('dequeueMultiple')
            ->willReturn($indexerMock);
        $indexerMock
            ->expects(self::once())
            ->method('enqueueEligible')
            ->with([2])
            ->willReturnCallback(static fn (): array => []);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() leaves the search index untouched when
     * the indexer accepts the record and puts it back into the queue.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordKeepsDocumentWhenRecordIsEligible(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);
        $indexerMock
            ->method('dequeueMultiple')
            ->willReturn($indexerMock);
        $indexerMock
            ->method('enqueueEligible')
            ->willReturnCallback(static fn (array $recordUids): array => $recordUids);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() keeps the document when one indexing
     * service rejects the record but another service that writes to the same
     * search index accepts it, because both address the same document with the
     * default document ID.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordKeepsDocumentWhenAnotherServiceOfTheSameIndexAccepts(): void
    {
        $this->insertSecondPageIndexingService(1);

        $this->bindIndexerVerdicts([1 => 0, 4 => 1]);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() removes the document once when every
     * indexing service that writes to the same search index rejects the record.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordRemovesDocumentOnceWhenEveryServiceOfTheSameIndexRejects(): void
    {
        $this->insertSecondPageIndexingService(1);

        $this->bindIndexerVerdicts([1 => 0, 4 => 0]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() removes the document from each search
     * index separately when the services of different indexes all reject it.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordRemovesDocumentFromEachIndexWhenAllServicesReject(): void
    {
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);

        $this->bindIndexerVerdicts([1 => 0, 4 => 0]);

        $indexNames  = [];
        $deleteCalls = 0;

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturnCallback(
                static function (string $indexName) use (&$indexNames, $searchEngineServiceMock): SearchEngineInterface {
                    $indexNames[] = $indexName;

                    return $searchEngineServiceMock;
                }
            );
        $searchEngineServiceMock
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function () use (&$deleteCalls): void {
                    ++$deleteCalls;
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);

        sort($indexNames);

        self::assertSame(2, $deleteCalls);
        self::assertSame(['other_index', 'test_index'], $indexNames);
    }

    /**
     * Tests that updateRecordsInQueue() removes the document only from the
     * index whose services all reject the record, and keeps it in an index
     * where another service accepts it.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordRemovesDocumentOnlyFromTheIndexWithoutAcceptingService(): void
    {
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);

        // Service 1 (test_index) accepts, service 4 (other_index) rejects
        $this->bindIndexerVerdicts([1 => 1, 4 => 0]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('withIndexName')
            ->with('other_index')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that two search engine records with the same engine and the same
     * index name count as one index, whatever their UIDs are.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordTreatsSearchEngineRecordsOfTheSameIndexAsOne(): void
    {
        $this->insertSearchEngine(2, 'algolia', 'test_index');
        $this->insertSecondPageIndexingService(2);

        $this->bindIndexerVerdicts([1 => 1, 4 => 0]);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that two search engine records with the same index name but a
     * different engine are separate indexes.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordSeparatesIndexesOfDifferentEngines(): void
    {
        $this->insertSearchEngine(2, 'other-engine', 'test_index');
        $this->insertSecondPageIndexingService(2);

        $this->bindIndexerVerdicts([1 => 1, 4 => 0]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() logs a failing removal instead of
     * letting it abort the action of the editor, and still removes the
     * document from the next index.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordLogsFailingRemovalAndContinuesWithTheNextIndex(): void
    {
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);

        $this->bindIndexerVerdicts([1 => 0, 4 => 0]);

        $attempts     = 0;
        $failure      = new RuntimeException('Search engine is not reachable');
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function () use (&$attempts, $failure): void {
                    ++$attempts;

                    if ($attempts === 1) {
                        throw $failure;
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('could not be removed'),
                [
                    'tableName'   => 'pages',
                    'recordCount' => 1,
                    'recordUids'  => [2],
                    'exception'   => $failure,
                ]
            );

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2]);

        self::assertSame(2, $attempts);
    }

    /**
     * Tests that updateRecordsInQueue() still removes the document when the
     * service of another site writes to a different index.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordRemovesDocumentWhenAServiceOfAnotherSiteWritesToAnotherIndex(): void
    {
        $this->insertSecondSiteRoot();
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2, 10);

        $this->bindIndexerVerdicts([1 => 0, 4 => 1]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() does not abort when the search engine of
     * an indexing service cannot be resolved, logs it, and decides on the
     * services whose index is known.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordDoesNotAbortWhenTheSearchEngineOfAServiceIsMissing(): void
    {
        // The search engine record of the second service does not exist
        $this->insertSecondPageIndexingService(99);

        $this->bindIndexerVerdicts([1 => 0, 4 => 1]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('could not be resolved'));

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that a service of another site is taken into account for every
     * rejected index, not only for the first one it is looked at.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordChecksTheServiceOfAnotherSiteForEachRejectedIndex(): void
    {
        $this->insertSecondSiteRoot();
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);
        // A service of the other site that is not consulted and writes to the second index
        $this->insertSecondPageIndexingService(2, 10, 5);

        $this->bindIndexerVerdicts([1 => 0, 4 => 0]);

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

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);

        // Only the index without a foreign writer loses the document
        self::assertSame(['test_index'], $indexNames);
    }

    /**
     * Tests that a service whose search engine cannot be resolved is logged
     * once per call, although several rejected indexes look at it.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordLogsAnUnresolvedSearchEngineOncePerCall(): void
    {
        $this->insertSecondSiteRoot();
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);
        // A service of the other site, not consulted, whose search engine does not exist
        $this->insertSecondPageIndexingService(99, 10, 5);

        $this->bindIndexerVerdicts([1 => 0, 4 => 0]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('could not be resolved'));

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2]);
    }

    /**
     * Tests that updateRecordsInQueue() does nothing when no indexers
     * match the given table type (factory returns null).
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordDoesNothingWhenNoIndexersMatch(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn(null);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->updateRecordsInQueue(1, 'pages', [2]);
    }

    // -----------------------------------------------------------------------
    // removeRecordsFromTargets
    // -----------------------------------------------------------------------

    /**
     * Tests that removeRecordsFromTargets() logs a failing search engine as an error
     * instead of aborting the action of the editor. The records are dequeued
     * before the search engine is asked.
     */
    #[Test]
    public function removeRecordsFromTargetsLogsAFailureOfTheSearchEngine(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([1, 2]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->method('deleteManyFromIndex')
            ->willThrowException(new RuntimeException('Algolia is not reachable'));

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('could not be removed'));

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            [1, 2],
        );
    }

    /**
     * Tests that removeRecordsFromTargets() logs a search engine that cannot be resolved,
     * for example one whose record was deleted, and still dequeues the records.
     * The failure must not stop the targets that follow.
     */
    #[Test]
    public function removeRecordsFromTargetsLogsAnUnresolvedSearchEngineAndGoesOnWithTheNextTarget(): void
    {
        $brokenService = $this->createMock(IndexingService::class);
        $brokenService
            ->method('getSearchEngine')
            ->willThrowException(new RuntimeException('The search engine record is gone'));

        $brokenIndexer = $this->createMock(IndexerInterface::class);
        $brokenIndexer
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([4, 5]);

        $workingEngine = $this->createMock(SearchEngine::class);
        $workingEngine->method('getIndexName')->willReturn('test_index');
        $workingEngine->method('getEngine')->willReturn('algolia');

        $workingService = $this->createMock(IndexingService::class);
        $workingService->method('getSearchEngine')->willReturn($workingEngine);

        $workingIndexer = $this->createMock(IndexerInterface::class);
        $workingIndexer
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([4, 5]);

        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [4, 5]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('could not be removed'));

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$brokenService, $brokenIndexer], [$workingService, $workingIndexer]],
            'pages',
            [4, 5]
        );
    }

    /**
     * Tests that a failing queue removal is logged and does not keep the
     * documents in the index, which are removed with the next call anyway.
     */
    #[Test]
    public function removeRecordsFromTargetsRemovesTheDocumentsWhenTheQueueRemovalFails(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('dequeueMultiple')
            ->willThrowException(new RuntimeException('The queue table is locked'));

        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [4]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('Queue items'));

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            [4],
        );
    }

    /**
     * Tests that removeRecordsFromTargets() sends the records in blocks and goes on with
     * the next block when one block fails, so that one failure does not leave
     * the documents of all following records in the index.
     */
    #[Test]
    public function removeRecordsFromTargetsContinuesWithTheNextBlockAfterAFailure(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);

        $blockSizes    = [];
        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$blockSizes): void {
                    $blockSizes[] = count($uids);

                    if (count($blockSizes) === 1) {
                        throw new RuntimeException('Algolia is not reachable');
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('could not be removed'));

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            range(1, 1001),
        );

        self::assertSame([1000, 1], $blockSizes, 'The block after the failing one is sent');
    }

    /**
     * Tests that removeRecordsFromTargets() stops sending after repeated failures, so that
     * an unreachable search engine costs the request of the editor a few failed
     * blocks and not one per block of a large list. What is left is logged.
     */
    #[Test]
    public function removeRecordsFromTargetsStopsAfterRepeatedFailures(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);

        $blockSizes    = [];
        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$blockSizes): never {
                    $blockSizes[] = count($uids);

                    throw new RuntimeException('Algolia is not reachable');
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::exactly(2))
            ->method('error')
            ->with(self::stringContains('could not be removed'));
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('stopped'),
                self::callback(
                    static fn (array $context): bool => $context['unsentRecordCount'] === 1002
                )
            );

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            range(1, 3002),
        );

        self::assertSame([1000, 1000], $blockSizes, 'No block is sent after the second failure');
    }

    /**
     * Tests that failed blocks that are not in a row do not stop the removal,
     * because a success in between starts the count again.
     */
    #[Test]
    public function removeRecordsFromTargetsDoesNotStopForFailuresThatAreNotInARow(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);

        $blockSizes    = [];
        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$blockSizes): void {
                    $blockSizes[] = count($uids);

                    // The first and the third block fail, the second and the last one succeed
                    if ((count($blockSizes) % 2) === 1) {
                        throw new RuntimeException('Algolia is not reachable');
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::exactly(2))
            ->method('error')
            ->with(self::stringContains('could not be removed'));
        $logger
            ->expects(self::never())
            ->method('warning');

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            range(1, 3500),
        );

        self::assertSame([1000, 1000, 1000, 500], $blockSizes);
    }

    /**
     * Tests that nothing is logged as not sent when the failed block that reaches
     * the limit is the last one, because every record was sent.
     */
    #[Test]
    public function removeRecordsFromTargetsLogsNoUnsentRecordsWhenTheSecondFailureIsTheLastBlock(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);

        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->method('deleteManyFromIndex')
            ->willThrowException(new RuntimeException('Algolia is not reachable'));

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::exactly(2))
            ->method('error');
        $logger
            ->expects(self::never())
            ->method('warning');

        // More records than one block holds, so there is a full block and a short last one, and both fail
        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            range(1, 1500),
        );
    }

    /**
     * Tests that removeRecordsFromTargets() dequeues multiple records at once
     * and removes them from the search engine index with one batch call.
     */
    #[Test]
    public function removeRecordsFromTargetsDequeuesMultipleAndRemovesFromIndex(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([1, 2, 3]);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('pages', [1, 2, 3]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            [1, 2, 3],
        );
    }

    // -----------------------------------------------------------------------
    // updateRecordsInQueue
    // -----------------------------------------------------------------------

    /**
     * Tests that updateRecordsInQueue() dequeues and queues the whole list
     * once per service and does not look at a search engine when every record
     * is accepted.
     */
    #[Test]
    public function updateRecordsInQueueAcceptsAllRecordsWithOneCallPerService(): void
    {
        $calls = [];

        $this->bindBulkIndexerVerdicts([1 => [2, 3]], $calls);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->updateRecordsInQueue(1, 'pages', [2, 3]);

        self::assertSame(
            [
                ['dequeueMultiple', 1, [2, 3]],
                ['enqueueEligible', 1, [2, 3]],
            ],
            $calls
        );
    }

    /**
     * Tests that updateRecordsInQueue() removes the documents of the rejected
     * records from the index in one batch and leaves the accepted ones alone.
     */
    #[Test]
    public function updateRecordsInQueueRemovesTheRejectedDocumentsInOneBatch(): void
    {
        $removals = [];

        $this->bindBulkIndexerVerdicts([1 => [2]]);
        $this->bindRecordingSearchEngine($removals);

        $this->subject->updateRecordsInQueue(1, 'pages', [2, 3, 4]);

        self::assertSame([['test_index', 'pages', [3, 4]]], $removals);
    }

    /**
     * Tests that updateRecordsInQueue() keeps the document of a record that
     * another consulted service writing to the same index accepts, and removes
     * only the records that none of them accepts.
     */
    #[Test]
    public function updateRecordsInQueueKeepsTheDocumentsAcceptedByAnotherServiceOfTheSameIndex(): void
    {
        $removals = [];

        $this->insertSecondPageIndexingService(1);
        $this->bindBulkIndexerVerdicts([1 => [2], 4 => [3]]);
        $this->bindRecordingSearchEngine($removals);

        $this->subject->updateRecordsInQueue(1, 'pages', [2, 3, 4]);

        self::assertSame([['test_index', 'pages', [4]]], $removals);
    }

    /**
     * Tests that a rejection of the first service is not hidden by a service
     * that writes to another index and accepts every record, so the index of
     * the first service loses the documents.
     */
    #[Test]
    public function updateRecordsInQueueRemovesTheDocumentsOfAnIndexWhenALaterServiceOfAnotherIndexAccepts(): void
    {
        $removals = [];

        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);
        $this->bindBulkIndexerVerdicts([1 => [], 4 => [2, 3]]);
        $this->bindRecordingSearchEngine($removals);

        $this->subject->updateRecordsInQueue(1, 'pages', [2, 3]);

        self::assertSame([['test_index', 'pages', [2, 3]]], $removals);
    }

    /**
     * Tests that a failing check of the allowed file extensions in the batch
     * decision keeps the documents and is logged as an error, and does not
     * abort the action of the editor.
     */
    #[Test]
    public function updateRecordsInQueueKeepsTheDocumentsAndLogsWhenTheExtensionCheckFails(): void
    {
        $this->insertFileIndexingService();

        $fileIndexer = $this->createMock(FileIndexer::class);
        $fileIndexer->method('withIndexingService')->willReturn($fileIndexer);
        $fileIndexer->method('dequeueMultiple')->willReturn($fileIndexer);
        $fileIndexer->method('enqueueEligible')->willReturn([]);
        $fileIndexer
            ->method('hasAllowedFileExtensions')
            ->willThrowException(new RuntimeException('TypoScript is not available'));

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($fileIndexer);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('could not be removed'));

        $this->createSubject($logger)->updateRecordsInQueue(1, 'sys_file_metadata', [7, 8]);
    }

    /**
     * Tests that a rejecting service whose search engine cannot be resolved
     * takes no part in the decision about the documents of an index, and that
     * this is logged once as a warning and not as an error.
     */
    #[Test]
    public function updateRecordsInQueueTakesNoActionForARejectingServiceWithAMissingSearchEngine(): void
    {
        $removals = [];

        $this->insertSecondPageIndexingService(99);
        $this->bindBulkIndexerVerdicts([1 => [2], 4 => []]);
        $this->bindRecordingSearchEngine($removals);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('could not be resolved'));
        $logger
            ->expects(self::never())
            ->method('error');

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2, 3]);

        self::assertSame([['test_index', 'pages', [3]]], $removals);
    }

    /**
     * Tests that no search engine is looked at when every service accepts every
     * record, so a service with a missing search engine does not log anything.
     */
    #[Test]
    public function updateRecordsInQueueDoesNotResolveSearchEnginesWhenEveryServiceAcceptsEveryRecord(): void
    {
        $this->insertSecondPageIndexingService(99);
        $this->bindBulkIndexerVerdicts([1 => [2, 3], 4 => [2, 3]]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2, 3]);
    }

    /**
     * Tests that a record that is given more than once is handed to the
     * indexers once.
     */
    #[Test]
    public function updateRecordsInQueueHandsEachRecordToTheIndexersOnce(): void
    {
        $calls = [];

        $this->bindBulkIndexerVerdicts([1 => [2, 3]], $calls);

        $this->subject->updateRecordsInQueue(1, 'pages', [2, 2, 3]);

        self::assertSame(
            [
                ['dequeueMultiple', 1, [2, 3]],
                ['enqueueEligible', 1, [2, 3]],
            ],
            $calls
        );
    }

    /**
     * Tests that updateRecordsInQueue() keeps the documents and logs a warning
     * when a service of another site that was not consulted writes to the
     * same index.
     */
    #[Test]
    public function updateRecordsInQueueKeepsTheDocumentsWhenAnUnconsultedServiceWritesToTheSameIndex(): void
    {
        $this->insertSecondSiteRoot();
        $this->insertSecondPageIndexingService(1, 10);

        $this->bindBulkIndexerVerdicts([1 => []]);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('not consulted'),
                ['tableName' => 'pages', 'recordCount' => 2, 'recordUids' => [2, 3]]
            );

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2, 3]);
    }

    /**
     * Tests that updateRecordsInQueue() still queues the accepted records but
     * removes no document when the caller asks for no removal.
     */
    #[Test]
    public function updateRecordsInQueueRemovesNothingWhenTheCallerAsksForNoRemoval(): void
    {
        $calls = [];

        $this->bindBulkIndexerVerdicts([1 => []], $calls);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->updateRecordsInQueue(1, 'pages', [2, 3], false);

        self::assertSame(
            [
                ['dequeueMultiple', 1, [2, 3]],
                ['enqueueEligible', 1, [2, 3]],
            ],
            $calls
        );
    }

    /**
     * Tests that updateRecordsInQueue() consults the services of the former
     * site as well, so that the documents of a record that moved are removed
     * from the index of the former site.
     */
    #[Test]
    public function updateRecordsInQueueConsultsTheServicesOfBothSites(): void
    {
        $calls    = [];
        $removals = [];

        $this->insertSecondSiteRoot();
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2, 10);

        $this->bindBulkIndexerVerdicts([1 => [2, 3], 4 => [2]], $calls);
        $this->bindRecordingSearchEngine($removals);

        $this->subject->updateRecordsInQueue(1, 'pages', [2, 3], true, 10);

        $decided = [];

        foreach ($calls as [$method, $serviceUid]) {
            if ($method === 'enqueueEligible') {
                $decided[] = $serviceUid;
            }
        }

        self::assertSame([1, 4], $decided);
        self::assertSame([['other_index', 'pages', [3]]], $removals);
    }

    /**
     * Tests that updateRecordsInQueue() consults only the services of the site
     * when the records come from outside of any site, whose former root page
     * is 0 and would otherwise match a service stored outside of any site.
     */
    #[Test]
    public function updateRecordsInQueueConsultsOnlyTheSiteWhenTheRecordsComeFromOutsideOfAnySite(): void
    {
        $this->insertPageOutsideOfAnySite();
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2, 20);

        // The second service is not bound, so asking it fails the test
        $this->bindBulkIndexerVerdicts([1 => [2]]);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->updateRecordsInQueue(1, 'pages', [2], true, 0);
    }

    /**
     * Tests that updateRecordsInQueue() does nothing for an empty list, because
     * the queue reads an empty list as every record of the table.
     */
    #[Test]
    public function updateRecordsInQueueDoesNothingForAnEmptyList(): void
    {
        $this->indexerFactoryMock
            ->expects(self::never())
            ->method('makeInstanceByType');

        $this->subject->updateRecordsInQueue(1, 'pages', []);
    }

    /**
     * Tests that updateRecordsInQueue() keeps the documents of rejected files
     * and logs a warning when the allowed file extensions are not configured,
     * because the indexer then rejects every file.
     */
    #[Test]
    public function updateRecordsInQueueKeepsTheDocumentsOfRejectedFilesWithoutConfiguredExtensions(): void
    {
        $this->insertFileIndexingService();
        $this->bindBulkFileIndexer(false);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('allowed file extensions are not configured'),
                ['tableName' => 'sys_file_metadata', 'recordCount' => 2, 'recordUids' => [7, 8]]
            );

        $this->createSubject($logger)->updateRecordsInQueue(1, 'sys_file_metadata', [7, 8]);
    }

    /**
     * Tests that updateRecordsInQueue() removes the documents of rejected files
     * once the allowed file extensions are configured.
     */
    #[Test]
    public function updateRecordsInQueueRemovesTheDocumentsOfRejectedFilesWithConfiguredExtensions(): void
    {
        $removals = [];

        $this->insertFileIndexingService();
        $this->bindBulkFileIndexer(true);
        $this->bindRecordingSearchEngine($removals);

        $this->subject->updateRecordsInQueue(1, 'sys_file_metadata', [7, 8]);

        self::assertSame([['test_index', 'sys_file_metadata', [7, 8]]], $removals);
    }

    // -----------------------------------------------------------------------
    // processContentElementsOfPage with a known list
    // -----------------------------------------------------------------------

    /**
     * Tests that processContentElementsOfPage() queues nothing for an empty
     * list of content elements, because the queue reads an empty list as every
     * record of the table.
     */
    #[Test]
    public function processContentElementsOfPageQueuesNothingForAnEmptyList(): void
    {
        $contentIndexerMock = $this->createMock(ContentIndexer::class);
        $contentIndexerMock
            ->method('withIndexingService')
            ->willReturn($contentIndexerMock);
        $contentIndexerMock
            ->expects(self::never())
            ->method('enqueueEligible');

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($contentIndexerMock);

        $this->subject->processContentElementsOfPage(2, false, []);
    }

    /**
     * Tests that processContentElementsOfPage() takes the content elements out of
     * the queue before it queues them again, so that elements that are still
     * pending do not collide with their own queue entries one by one.
     */
    #[Test]
    public function processContentElementsOfPageDequeuesTheElementsBeforeItQueuesThem(): void
    {
        $calls = [];

        $contentIndexerMock = $this->createMock(ContentIndexer::class);
        $contentIndexerMock->method('withExcludeHiddenPages')->willReturnSelf();
        $contentIndexerMock
            ->method('withIndexingService')
            ->willReturn($contentIndexerMock);
        $contentIndexerMock
            ->method('dequeueMultiple')
            ->willReturnCallback(
                static function (array $uids) use (&$calls, $contentIndexerMock): IndexerInterface {
                    $calls[] = ['dequeueMultiple', $uids];

                    return $contentIndexerMock;
                }
            );
        $contentIndexerMock
            ->method('enqueueEligible')
            ->willReturnCallback(
                static function (array $uids) use (&$calls): array {
                    $calls[] = ['enqueueEligible', $uids];

                    return $uids;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($contentIndexerMock);

        $this->subject->processContentElementsOfPage(2, false, [901, 902]);

        self::assertSame(
            [
                ['dequeueMultiple', [901, 902]],
                ['enqueueEligible', [901, 902]],
            ],
            $calls
        );
    }

    /**
     * Tests that processContentElementsOfPage() removes nothing for an empty
     * list of content elements and does not ask the search engine.
     */
    #[Test]
    public function processContentElementsOfPageRemovesNothingForAnEmptyList(): void
    {
        $contentIndexerMock = $this->createMock(ContentIndexer::class);
        $contentIndexerMock
            ->method('withIndexingService')
            ->willReturn($contentIndexerMock);
        $contentIndexerMock
            ->expects(self::never())
            ->method('dequeueMultiple');

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($contentIndexerMock);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->processContentElementsOfPage(2, true, []);
    }

    // -----------------------------------------------------------------------
    // log context of a long list
    // -----------------------------------------------------------------------

    /**
     * Tests that the log entry of a failing removal of many records carries
     * the number of records and only the first of their UIDs, so that a large
     * tree does not produce an unbounded log entry.
     */
    #[Test]
    public function removeRecordsFromTargetsLogsTheCountAndOnlyTheFirstUidsOfAFailingRemoval(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->method('deleteManyFromIndex')
            ->willThrowException(new RuntimeException('Algolia is not reachable'));

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $contexts = [];
        $logger   = $this->createMock(LoggerInterface::class);
        $logger
            ->method('error')
            ->willReturnCallback(
                static function (string $message, array $context) use (&$contexts): void {
                    $contexts[] = $context;
                }
            );

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $this->createMock(IndexerInterface::class)]],
            'pages',
            range(1, 50),
        );

        self::assertCount(1, $contexts);
        self::assertSame(50, $contexts[0]['recordCount']);
        self::assertSame(range(1, 20), $contexts[0]['recordUids']);
        self::assertArrayNotHasKey('recordUid', $contexts[0]);
    }

    /**
     * Binds a file indexer for the bulk decision that rejects every record.
     *
     * @param bool $hasExtensions The answer of the file indexer whether file extensions are configured
     *
     * @return void
     */
    private function bindBulkFileIndexer(bool $hasExtensions): void
    {
        $fileIndexer = $this->createMock(FileIndexer::class);
        $fileIndexer
            ->method('withIndexingService')
            ->willReturn($fileIndexer);
        $fileIndexer
            ->method('dequeueMultiple')
            ->willReturn($fileIndexer);
        $fileIndexer
            ->method('enqueueEligible')
            ->willReturn([]);
        $fileIndexer
            ->method('hasAllowedFileExtensions')
            ->willReturn($hasExtensions);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($fileIndexer);
    }

    /**
     * Binds the bulk verdict of every indexer to its indexing service.
     *
     * An indexer accepts the records of its list that its service is bound to,
     * and every call is recorded.
     *
     * @param array<int, int[]>               $acceptedByService The accepted record UIDs by UID of the indexing service
     * @param list<array{string, int, int[]}> $calls             Receives every call as method, service UID and record UIDs
     *
     * @return void
     */
    private function bindBulkIndexerVerdicts(
        array $acceptedByService,
        array &$calls = [],
    ): void {
        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                function (IndexingService $service) use ($acceptedByService, &$calls): IndexerInterface {
                    $serviceUid = (int) $service->getUid();
                    $accepted   = $acceptedByService[$serviceUid] ?? throw new RuntimeException(
                        'No verdict bound for indexing service ' . $serviceUid
                    );

                    $indexerMock = $this->createMock(IndexerInterface::class);
                    $indexerMock
                        ->method('dequeueMultiple')
                        ->willReturnCallback(
                            static function (array $recordUids) use (&$calls, $serviceUid, $indexerMock): IndexerInterface {
                                $calls[] = ['dequeueMultiple', $serviceUid, $recordUids];

                                return $indexerMock;
                            }
                        );
                    $indexerMock
                        ->method('enqueueEligible')
                        ->willReturnCallback(
                            static function (array $recordUids) use (&$calls, $serviceUid, $accepted): array {
                                $calls[] = ['enqueueEligible', $serviceUid, $recordUids];

                                return array_values(array_intersect($recordUids, $accepted));
                            }
                        );

                    return $indexerMock;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);
    }

    /**
     * Binds a search engine double that records every removal.
     *
     * @param list<array{string, string, int[]}> $removals Receives the index name, table and UIDs of every removal
     *
     * @return void
     */
    private function bindRecordingSearchEngine(array &$removals): void
    {
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
                static function (string $table, array $recordUids) use (&$removals, &$indexName): void {
                    $removals[] = [$indexName, $table, $recordUids];
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);
    }

    /**
     * Adds a page that is not part of any site.
     *
     * @return void
     */
    private function insertPageOutsideOfAnySite(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => 20,
                    'pid'         => 0,
                    'title'       => 'Page Outside Of Any Site',
                    'is_siteroot' => 0,
                    'doktype'     => 1,
                    'deleted'     => 0,
                    'hidden'      => 0,
                    'no_search'   => 0,
                ]
            );
    }

    /**
     * Binds the verdict of every indexer to its indexing service, so that a
     * test does not depend on the order the services are returned in.
     *
     * @param array<int, int> $verdicts The verdict (1 accepts, 0 rejects) by UID of the indexing service
     *
     * @return void
     */
    private function bindIndexerVerdicts(array $verdicts): void
    {
        $factoryIndexer = $this->createMock(IndexerInterface::class);
        $factoryIndexer
            ->method('withIndexingService')
            ->willReturnCallback(
                fn (IndexingService $service): IndexerInterface => $this->createIndexerMock(
                    $verdicts[$service->getUid()] ?? throw new RuntimeException(
                        'No verdict bound for indexing service ' . $service->getUid()
                    )
                )
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factoryIndexer);
    }

    /**
     * Creates the record handler with the given optional logger.
     *
     * @param LoggerInterface|null $logger The logger or NULL
     *
     * @return RecordHandler
     */
    private function createSubject(?LoggerInterface $logger = null): RecordHandler
    {
        return new RecordHandler(
            $this->searchEngineFactoryMock,
            $this->indexerFactoryMock,
            new PageRepository($this->getConnectionPool()),
            $this->get(IndexingServiceRepository::class),
            new ContentRepository($this->getConnectionPool()),
            $logger,
        );
    }

    /**
     * Adds a search engine record.
     *
     * @param int    $uid       The UID of the search engine
     * @param string $engine    The engine type
     * @param string $indexName The name of the index
     *
     * @return void
     */
    private function insertSearchEngine(
        int $uid,
        string $engine,
        string $indexName,
    ): void {
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_searchengine')
            ->insert(
                'tx_typo3searchalgolia_domain_model_searchengine',
                [
                    'uid'        => $uid,
                    'pid'        => 1,
                    'title'      => 'Engine ' . $uid,
                    'engine'     => $engine,
                    'index_name' => $indexName,
                    'deleted'    => 0,
                ]
            );
    }

    /**
     * Adds a file indexing service that writes to the first search engine.
     *
     * @return void
     */
    private function insertFileIndexingService(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'           => 5,
                    'pid'           => 1,
                    'title'         => 'File Indexer',
                    'type'          => 'sys_file_metadata',
                    'search_engine' => 1,
                    'deleted'       => 0,
                ]
            );
    }

    /**
     * Adds a second page indexing service that writes to the given search engine.
     *
     * @param int $searchEngineUid The UID of the search engine the service writes to
     * @param int $pid             The page the service is stored on, which decides its site
     * @param int $uid             The UID of the indexing service
     *
     * @return void
     */
    private function insertSecondPageIndexingService(
        int $searchEngineUid,
        int $pid = 1,
        int $uid = 4,
    ): void {
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => $uid,
                    'pid'             => $pid,
                    'title'           => 'Second Page Indexer',
                    'type'            => 'pages',
                    'search_engine'   => $searchEngineUid,
                    'deleted'         => 0,
                    'pages_recursive' => '1',
                    'pages_doktype'   => '1',
                ]
            );
    }

    /**
     * Adds a second site root page.
     *
     * @return void
     */
    private function insertSecondSiteRoot(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => 10,
                    'pid'         => 0,
                    'title'       => 'Second Root Page',
                    'is_siteroot' => 1,
                    'doktype'     => 1,
                    'deleted'     => 0,
                    'hidden'      => 0,
                    'no_search'   => 0,
                ]
            );
    }

    /**
     * Tests that updateRecordsInQueue() does not look at the search engines
     * either when the caller asks for no removal.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordDoesNotResolveSearchEnginesWhenNoRemovalIsWanted(): void
    {
        // The search engine record of the second service does not exist
        $this->insertSecondPageIndexingService(99);

        $this->bindIndexerVerdicts([1 => 0, 4 => 0]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::never())
            ->method('warning');

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2], false);
    }

    /**
     * Tests that removeRecordsFromTargets() also logs a warning when the search engine
     * factory returns no service, because the documents then stay in the index.
     */
    #[Test]
    public function removeRecordsFromTargetsLogsAWarningWhenNoSearchEngineServiceCanBeCreated(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getEngine')->willReturn('unknown');
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([1, 2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('No search engine service could be created'),
                ['engine' => 'unknown', 'indexName' => 'test_index']
            );

        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            [1, 2],
        );
    }

    /**
     * Tests that removeRecords() resolves the services once and removes all
     * given records from the queue in one batch and from the index in one batch
     * call of the search engine.
     */
    #[Test]
    public function removeRecordsRemovesAllRecordsWithOneBatchPerService(): void
    {
        $indexerMock = $this->createIndexerMock(1);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([2, 3])
            ->willReturn($indexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $removed      = [];
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$removed): void {
                    $removed[] = [$table, $uids];
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $this->subject->removeRecords(1, 'pages', [2, 3]);

        self::assertSame([['pages', [2, 3]]], $removed);
    }

    /**
     * Tests that removeRecords() does not look for indexing services when it
     * has nothing to remove.
     */
    #[Test]
    public function removeRecordsDoesNothingWithoutRecords(): void
    {
        $this->indexerFactoryMock
            ->expects(self::never())
            ->method('makeInstanceByType');

        $this->subject->removeRecords(1, 'pages', []);
    }

    /**
     * Tests that an empty list of records removes nothing from the targets. The
     * queue reads an empty list as every record of the table, so a page tree
     * without content elements must never reach it.
     */
    #[Test]
    public function removeRecordsFromTargetsKeepsTheQueueForAnEmptyList(): void
    {
        $indexerMock = $this->createIndexerMock(0);
        $indexerMock
            ->expects(self::never())
            ->method('dequeueMultiple');

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $targets = $this->subject->resolveIndexerTargets(1, 'pages');

        self::assertNotSame([], $targets, 'There is a target that could be reached');

        $this->subject->removeRecordsFromTargets($targets, 'pages', []);
    }

    /**
     * Tests that removeRecords() removes a single record from the queue and from
     * the search index for every indexing service of the page tree of the root page.
     */
    #[Test]
    public function removeRecordsDequeuesAndRemovesFromIndexForEveryService(): void
    {
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);

        $indexerMock = $this->createIndexerMock(1);
        $indexerMock
            ->expects(self::exactly(2))
            ->method('dequeueMultiple')
            ->with([2])
            ->willReturn($indexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

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
            ->expects(self::exactly(2))
            ->method('deleteManyFromIndex')
            ->with('pages', [2]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $this->subject->removeRecords(1, 'pages', [2]);

        self::assertEqualsCanonicalizing(['test_index', 'other_index'], $indexNames);
    }

    /**
     * Tests that removeRecords() does nothing when no indexing service is
     * responsible for the root page.
     */
    #[Test]
    public function removeRecordsDoesNothingWithoutAResponsibleService(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn(null);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->removeRecords(1, 'pages', [2]);
    }

    /**
     * Tests that removeRecords() logs a failing search engine as an error and goes
     * on with the next indexing service instead of aborting the action of the
     * editor. The record is dequeued for every service.
     */
    #[Test]
    public function removeRecordsLogsAFailureAndContinuesWithTheNextService(): void
    {
        $this->insertSearchEngine(2, 'algolia', 'other_index');
        $this->insertSecondPageIndexingService(2);

        $indexerMock = $this->createIndexerMock(1);
        $indexerMock
            ->expects(self::exactly(2))
            ->method('dequeueMultiple')
            ->with([2])
            ->willReturn($indexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $attempts     = 0;
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function () use (&$attempts): void {
                    ++$attempts;

                    if ($attempts === 1) {
                        throw new RuntimeException('Algolia is not reachable');
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('could not be removed'));

        $this->createSubject($logger)->removeRecords(1, 'pages', [2]);

        self::assertSame(2, $attempts, 'The second service is still asked');
    }

    /**
     * Tests that processContentElementsOfPage() works on the given content
     * element UIDs and does not look at the content of the page, which is
     * deleted already when the caller knows the UIDs from before.
     */
    #[Test]
    public function processContentElementsOfPageUsesTheGivenContentElementUids(): void
    {
        $contentIndexerMock = $this->createMock(ContentIndexer::class);
        $contentIndexerMock
            ->method('withIndexingService')
            ->willReturn($contentIndexerMock);
        $contentIndexerMock
            ->method('getTable')
            ->willReturn('tt_content');
        $contentIndexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([901, 902])
            ->willReturn($contentIndexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($contentIndexerMock);

        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->expects(self::once())
            ->method('deleteManyFromIndex')
            ->with('tt_content', [901, 902]);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        // Page 2 carries other content elements, which must not be touched
        $this->subject->processContentElementsOfPage(2, true, [901, 902]);
    }

    /**
     * Tests that removing the content elements of a page logs a failing search
     * engine as an error instead of aborting the action of the editor.
     */
    #[Test]
    public function processContentElementsOfPageLogsAFailureWhenRemovingTheElements(): void
    {
        $contentIndexerMock = $this->createMock(ContentIndexer::class);
        $contentIndexerMock
            ->method('withIndexingService')
            ->willReturn($contentIndexerMock);
        $contentIndexerMock
            ->method('getTable')
            ->willReturn('tt_content');
        $contentIndexerMock
            ->method('dequeueMultiple')
            ->willReturn($contentIndexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($contentIndexerMock);

        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willThrowException(new RuntimeException('Algolia is not reachable'));

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(self::stringContains('could not be removed'));

        $this->createSubject($logger)->processContentElementsOfPage(2, true, [1, 2]);
    }

    /**
     * Tests that the services of a site are consulted once when the former site
     * of a moved record is the same site.
     */
    #[Test]
    public function updateRecordsInQueueForOneRecordConsultsTheServicesOnceWhenThePreviousRootIsTheSame(): void
    {
        $indexerMock = $this->createIndexerMock(1);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueMultiple')
            ->with([2])
            ->willReturn($indexerMock);
        $indexerMock
            ->expects(self::once())
            ->method('enqueueEligible')
            ->with([2])
            ->willReturnCallback(static fn (array $recordUids): array => $recordUids);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $this->subject->updateRecordsInQueue(1, 'pages', [2], true, 1);
    }

    /**
     * Creates an indexer mock that reports the given result when the record is queued.
     *
     * @param int $enqueueResult 1 if the indexer accepts the record, 0 if it rejects it
     *
     * @return MockObject&IndexerInterface
     */
    private function createIndexerMock(int $enqueueResult): MockObject&IndexerInterface
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);
        $indexerMock
            ->method('dequeueMultiple')
            ->willReturn($indexerMock);
        $indexerMock
            ->method('enqueueEligible')
            ->willReturnCallback(
                static fn (array $recordUids): array => ($enqueueResult === 1) ? $recordUids : []
            );

        return $indexerMock;
    }

    /**
     * Tests that nothing is logged as not sent when the failed block that reaches
     * the limit is the last one and fills a whole block, so that no record was
     * left unsent.
     */
    #[Test]
    public function removeRecordsFromTargetsLogsNoUnsentRecordsWhenTheLastBlockIsFullAndFails(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);

        $searchService = $this->createMock(SearchEngineInterface::class);
        $searchService
            ->method('withIndexName')
            ->willReturn($searchService);
        $searchService
            ->method('deleteManyFromIndex')
            ->willThrowException(new RuntimeException('Algolia is not reachable'));

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchService);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::exactly(2))
            ->method('error');
        $logger
            ->expects(self::never())
            ->method('warning');

        // Two full blocks of records, and both fail
        $this->createSubject($logger)->removeRecordsFromTargets(
            [[$indexingServiceMock, $indexerMock]],
            'pages',
            range(1, 2000),
        );
    }

    /**
     * Tests that updateRecordsInQueue() logs nothing and removes nothing when
     * the records that one service rejects are accepted by another consulted
     * service of the same index, although a service that was not consulted
     * writes to that index too. There is no document left to remove, so there
     * is none to keep.
     */
    #[Test]
    public function updateRecordsInQueueLogsNothingWhenAnotherConsultedServiceAcceptsTheRejectedRecords(): void
    {
        $this->insertSecondSiteRoot();
        $this->insertSecondPageIndexingService(1, 1, 4);
        $this->insertSecondPageIndexingService(1, 10, 5);

        $this->bindBulkIndexerVerdicts([1 => [], 4 => [2, 3]]);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $this->createSubject($logger)->updateRecordsInQueue(1, 'pages', [2, 3]);
    }
}
