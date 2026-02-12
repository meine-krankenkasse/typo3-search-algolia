<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Unit tests for ContentIndexer.
 *
 * Tests non-database logic: table name, immutable pattern (withIndexingService,
 * withExcludeHiddenPages), and RuntimeException without indexing service.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(ContentIndexer::class)]
class ContentIndexerTest extends TestCase
{
    private ContentIndexer $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->createMock(ConnectionPool::class);

        $this->subject = new ContentIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->createMock(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );
    }

    #[Test]
    public function getTableReturnsTtContent(): void
    {
        self::assertSame('tt_content', $this->subject->getTable());
    }

    #[Test]
    public function withIndexingServiceReturnsNewInstance(): void
    {
        $indexingService = $this->createMock(IndexingService::class);
        $clone           = $this->subject->withIndexingService($indexingService);

        self::assertNotSame($this->subject, $clone);
        self::assertInstanceOf(IndexerInterface::class, $clone);
    }

    #[Test]
    public function withExcludeHiddenPagesReturnsNewInstance(): void
    {
        $clone = $this->subject->withExcludeHiddenPages(true);

        self::assertNotSame($this->subject, $clone);
        self::assertInstanceOf(IndexerInterface::class, $clone);
    }

    #[Test]
    public function enqueueOneThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->enqueueOne(1);
    }

    #[Test]
    public function dequeueOneThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->dequeueOne(1);
    }

    #[Test]
    public function dequeueAllThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->dequeueAll();
    }

    #[Test]
    public function enqueueMultipleThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->enqueueMultiple([1, 2]);
    }

    #[Test]
    public function enqueueAllThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->enqueueAll();
    }

    /**
     * Creates a ContentIndexer with properly configured mocks for happy-path testing.
     */
    private function createConfiguredSubject(
        ?QueueItemRepository $queueItemRepository = null,
        ?SearchEngineFactory $searchEngineFactory = null,
        ?DocumentBuilder $documentBuilder = null,
    ): ContentIndexer {
        $connectionPool = $this->createMock(ConnectionPool::class);

        return new ContentIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $searchEngineFactory ?? $this->createMock(SearchEngineFactory::class),
            $queueItemRepository ?? $this->createMock(QueueItemRepository::class),
            $documentBuilder ?? $this->createMock(DocumentBuilder::class),
        );
    }

    #[Test]
    public function dequeueOneCallsRepositoryWithCorrectParameters(): void
    {
        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('deleteByTableAndRecordUIDs')
            ->with('tt_content', [42], 7);

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock
            ->method('getUid')
            ->willReturn(7);

        $result = $this->createConfiguredSubject(queueItemRepository: $queueItemRepositoryMock)
            ->withIndexingService($indexingServiceMock)
            ->dequeueOne(42);

        self::assertInstanceOf(IndexerInterface::class, $result);
    }

    #[Test]
    public function dequeueMultipleCallsRepositoryWithCorrectParameters(): void
    {
        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('deleteByTableAndRecordUIDs')
            ->with('tt_content', [1, 2, 3], 7);

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock
            ->method('getUid')
            ->willReturn(7);

        $result = $this->createConfiguredSubject(queueItemRepository: $queueItemRepositoryMock)
            ->withIndexingService($indexingServiceMock)
            ->dequeueMultiple([1, 2, 3]);

        self::assertInstanceOf(IndexerInterface::class, $result);
    }

    #[Test]
    public function dequeueAllCallsDeleteByIndexingService(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('deleteByIndexingService')
            ->with($indexingServiceMock);

        $result = $this->createConfiguredSubject(queueItemRepository: $queueItemRepositoryMock)
            ->withIndexingService($indexingServiceMock)
            ->dequeueAll();

        self::assertInstanceOf(IndexerInterface::class, $result);
    }

    #[Test]
    public function indexRecordReturnsTrueOnSuccess(): void
    {
        $searchEngineMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineMock->expects(self::once())
            ->method('indexOpen')
            ->with('test_index');
        $searchEngineMock->expects(self::once())
            ->method('documentUpdate')
            ->willReturn(true);
        $searchEngineMock->expects(self::once())
            ->method('indexCommit');
        $searchEngineMock->expects(self::once())
            ->method('indexClose');

        $searchEngineModelMock = $this->createMock(SearchEngine::class);
        $searchEngineModelMock->method('getIndexName')
            ->willReturn('test_index');

        $searchEngineFactoryMock = $this->createMock(SearchEngineFactory::class);
        $searchEngineFactoryMock->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineMock);

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock->method('getSearchEngine')
            ->willReturn($searchEngineModelMock);

        $documentMock = $this->createMock(Document::class);

        $documentBuilderMock = $this->createMock(DocumentBuilder::class);
        $documentBuilderMock->method('setIndexer')->willReturnSelf();
        $documentBuilderMock->method('setRecord')->willReturnSelf();
        $documentBuilderMock->method('setIndexingService')->willReturnSelf();
        $documentBuilderMock->method('assemble')->willReturnSelf();
        $documentBuilderMock->method('getDocument')->willReturn($documentMock);

        $indexer = $this->createConfiguredSubject(
            searchEngineFactory: $searchEngineFactoryMock,
            documentBuilder: $documentBuilderMock,
        );

        $result = $indexer->indexRecord($indexingServiceMock, ['uid' => 1, 'title' => 'Test']);

        self::assertTrue($result);
    }

    #[Test]
    public function indexRecordReturnsFalseWhenNoSearchEngine(): void
    {
        $searchEngineModelMock = $this->createMock(SearchEngine::class);

        $searchEngineFactoryMock = $this->createMock(SearchEngineFactory::class);
        $searchEngineFactoryMock->method('makeInstanceBySearchEngineModel')
            ->willReturn(null);

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock->method('getSearchEngine')
            ->willReturn($searchEngineModelMock);

        $documentBuilderMock = $this->createMock(DocumentBuilder::class);
        $documentBuilderMock->expects(self::never())
            ->method('setIndexer');

        $indexer = $this->createConfiguredSubject(
            searchEngineFactory: $searchEngineFactoryMock,
            documentBuilder: $documentBuilderMock,
        );

        $result = $indexer->indexRecord($indexingServiceMock, ['uid' => 1]);

        self::assertFalse($result);
    }

    #[Test]
    public function indexRecordReturnsFalseWhenDocumentUpdateFails(): void
    {
        $searchEngineMock = $this->createMock(SearchEngineInterface::class);
        $searchEngineMock->expects(self::once())
            ->method('documentUpdate')
            ->willReturn(false);
        $searchEngineMock->expects(self::once())
            ->method('indexCommit');
        $searchEngineMock->expects(self::once())
            ->method('indexClose');

        $searchEngineModelMock = $this->createMock(SearchEngine::class);
        $searchEngineModelMock->method('getIndexName')
            ->willReturn('test_index');

        $searchEngineFactoryMock = $this->createMock(SearchEngineFactory::class);
        $searchEngineFactoryMock->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngineMock);

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock->method('getSearchEngine')
            ->willReturn($searchEngineModelMock);

        $documentMock = $this->createMock(Document::class);

        $documentBuilderMock = $this->createMock(DocumentBuilder::class);
        $documentBuilderMock->method('setIndexer')->willReturnSelf();
        $documentBuilderMock->method('setRecord')->willReturnSelf();
        $documentBuilderMock->method('setIndexingService')->willReturnSelf();
        $documentBuilderMock->method('assemble')->willReturnSelf();
        $documentBuilderMock->method('getDocument')->willReturn($documentMock);

        $indexer = $this->createConfiguredSubject(
            searchEngineFactory: $searchEngineFactoryMock,
            documentBuilder: $documentBuilderMock,
        );

        $result = $indexer->indexRecord($indexingServiceMock, ['uid' => 1]);

        self::assertFalse($result);
    }

    #[Test]
    public function withIndexingServiceSetsServiceOnClone(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock
            ->method('getUid')
            ->willReturn(1);

        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('deleteByTableAndRecordUIDs');

        $clone = $this->createConfiguredSubject(queueItemRepository: $queueItemRepositoryMock)
            ->withIndexingService($indexingServiceMock);

        // Should not throw RuntimeException since indexing service is set
        $clone->dequeueOne(1);
    }

    #[Test]
    public function withExcludeHiddenPagesSetsValueOnClone(): void
    {
        $clone = $this->subject->withExcludeHiddenPages(true);

        $reflection = new ReflectionProperty($clone, 'excludeHiddenPages');

        self::assertTrue($reflection->getValue($clone));

        $cloneFalse = $this->subject->withExcludeHiddenPages(false);

        self::assertFalse($reflection->getValue($cloneFalse));
    }
}
