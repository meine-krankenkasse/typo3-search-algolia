<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\DataHandling;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Unit tests for RecordHandler.
 *
 * Tests the record handling logic for search indexing operations including
 * record deletion from queue and index, and indexer generator creation.
 *
 * Note: getRecordRootPageId() tests are omitted here because they require
 * RootlineUtility which needs a full TYPO3 bootstrap. These should be covered
 * by functional tests.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordHandler::class)]
#[UsesClass(ContentRepository::class)]
#[UsesClass(CreateUniqueDocumentIdEvent::class)]
#[UsesClass(PageRepository::class)]
class RecordHandlerTest extends TestCase
{
    /**
     * @var MockObject&SearchEngineFactory
     */
    private MockObject $searchEngineFactoryMock;

    /**
     * @var MockObject&IndexingServiceRepository
     */
    private MockObject $indexingServiceRepositoryMock;

    private RecordHandler $recordHandler;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->searchEngineFactoryMock       = $this->createMock(SearchEngineFactory::class);
        $this->indexingServiceRepositoryMock = $this->createMock(IndexingServiceRepository::class);

        // PageRepository and ContentRepository are readonly classes and cannot be mocked.
        // Create real instances with mocked ConnectionPool (which is not readonly).
        $connectionPoolMock = $this->createMock(ConnectionPool::class);
        $pageRepository     = new PageRepository($connectionPoolMock);
        $contentRepository  = new ContentRepository($connectionPoolMock);

        $this->recordHandler = new RecordHandler(
            $this->searchEngineFactoryMock,
            $this->createMock(IndexerFactory::class),
            $pageRepository,
            $this->indexingServiceRepositoryMock,
            $contentRepository
        );
    }

    /**
     * Tests that deleteRecord() both dequeues the record from the indexer and removes it
     * from the search engine index when the removeFromIndex flag is set to true. Verifies
     * that the search engine service is obtained from the factory and deleteFromIndex is
     * called with the correct table name and record UID.
     */
    #[Test]
    public function deleteRecordDequeuesAndRemovesFromIndex(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock
            ->method('getIndexName')
            ->willReturn('test_index');
        $searchEngineMock
            ->method('getEngine')
            ->willReturn('algolia');

        $indexingServiceMock
            ->method('getSearchEngine')
            ->willReturn($searchEngineMock);

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

        $this->recordHandler->deleteRecord(
            $indexingServiceMock,
            $indexerMock,
            'pages',
            42,
            true
        );
    }

    /**
     * Tests that deleteRecord() only dequeues the record from the indexer without
     * attempting to remove it from the search engine index when the removeFromIndex
     * flag is set to false. Verifies that the search engine factory is never called.
     */
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

        $this->recordHandler->deleteRecord(
            $indexingServiceMock,
            $indexerMock,
            'pages',
            42,
            false
        );
    }

    /**
     * Tests that deleteRecords() dequeues multiple record UIDs at once from the indexer
     * and removes each one individually from the search engine index. Verifies that
     * dequeueMultiple is called with the full UID array and deleteFromIndex is called
     * once for each record.
     */
    #[Test]
    public function deleteRecordsDequeuesMultipleAndRemovesFromIndex(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $searchEngineMock = $this->createMock(SearchEngine::class);
        $searchEngineMock
            ->method('getIndexName')
            ->willReturn('test_index');
        $searchEngineMock
            ->method('getEngine')
            ->willReturn('algolia');

        $indexingServiceMock
            ->method('getSearchEngine')
            ->willReturn($searchEngineMock);

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

        $this->recordHandler->deleteRecords(
            $indexingServiceMock,
            $indexerMock,
            'pages',
            [1, 2, 3],
            true
        );
    }

    /**
     * Tests that createIndexerGenerator() yields no results when the indexing service
     * repository returns an empty list of services for the given table name. The generator
     * should produce an empty iterable.
     */
    #[Test]
    public function createIndexerGeneratorYieldsNothingWhenNoServices(): void
    {
        $queryResultMock = $this->createMock(QueryResultInterface::class);
        $queryResultMock
            ->method('valid')
            ->willReturn(false);

        $this->indexingServiceRepositoryMock
            ->method('findAllByTableName')
            ->willReturn($queryResultMock);

        $generator = $this->recordHandler->createIndexerGenerator(1, 'pages');

        self::assertSame([], iterator_to_array($generator));
    }
}
