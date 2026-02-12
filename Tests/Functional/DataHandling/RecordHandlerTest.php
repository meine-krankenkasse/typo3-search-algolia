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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;

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
    // updateRecordInQueue
    // -----------------------------------------------------------------------

    #[Test]
    public function updateRecordInQueueDequeuesAndEnqueuesRecord(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);

        $indexerMock
            ->expects(self::once())
            ->method('dequeueOne')
            ->with(2)
            ->willReturn($indexerMock);

        $indexerMock
            ->expects(self::once())
            ->method('enqueueOne')
            ->with(2);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $this->subject->updateRecordInQueue(1, 'pages', 2);
    }

    #[Test]
    public function updateRecordInQueueDoesNothingWhenNoIndexersMatch(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn(null);

        // Should not throw
        $this->subject->updateRecordInQueue(1, 'pages', 2);

        self::assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // deleteRecord / deleteRecords
    // -----------------------------------------------------------------------

    #[Test]
    public function deleteRecordDequeuesAndRemovesFromSearchEngine(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getIndexName')->willReturn('test_index');
        $searchEngineMock->method('getEngine')->willReturn('algolia');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueOne')
            ->with(42);

        $searchEngineServiceMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineServiceMock
            ->method('withIndexName')
            ->willReturn($searchEngineServiceMock);
        $searchEngineServiceMock
            ->expects(self::once())
            ->method('deleteFromIndex')
            ->with('pages', 42);

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->deleteRecord(
            $indexingServiceMock,
            $indexerMock,
            'pages',
            42,
            true
        );
    }

    #[Test]
    public function deleteRecordDequeuesWithoutRemovingFromIndex(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueOne')
            ->with(42);

        $this->searchEngineFactoryMock
            ->expects(self::never())
            ->method('makeInstanceBySearchEngineModel');

        $this->subject->deleteRecord(
            $indexingServiceMock,
            $indexerMock,
            'pages',
            42,
            false
        );
    }

    #[Test]
    public function deleteRecordsDequeuesMultipleAndRemovesFromIndex(): void
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
            ->expects(self::exactly(3))
            ->method('deleteFromIndex');

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineServiceMock);

        $this->subject->deleteRecords(
            $indexingServiceMock,
            $indexerMock,
            'pages',
            [1, 2, 3],
            true
        );
    }

    #[Test]
    public function deleteRecordSilentlyHandlesMissingSearchEngine(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock->method('getEngine')->willReturn('unknown');
        $indexingServiceMock->method('getSearchEngine')->willReturn($searchEngineMock);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('dequeueOne')
            ->with(42);

        // SearchEngineFactory returns null for unknown engine
        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn(null);

        // Should not throw, deleteRecordFromSearchEngine returns early
        $this->subject->deleteRecord(
            $indexingServiceMock,
            $indexerMock,
            'pages',
            42,
            true
        );

        self::assertTrue(true);
    }
}
