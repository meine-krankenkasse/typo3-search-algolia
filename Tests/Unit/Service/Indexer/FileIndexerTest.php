<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\Indexer;

use Doctrine\DBAL\Result;
use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\FileCollectionService;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\AbstractIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\Indexer\Fixtures\StaticFileCollectionTestSubject;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

use function array_search;
use function count;
use function implode;

/**
 * Unit tests for FileIndexer.
 *
 * Tests non-database logic: table name, immutable pattern (withIndexingService,
 * withExcludeHiddenPages), and RuntimeException without indexing service.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(FileIndexer::class)]
#[UsesClass(CategoryRepository::class)]
#[UsesClass(FileRepository::class)]
#[UsesClass(PageRepository::class)]
#[UsesClass(FileCollectionService::class)]
#[CoversClass(AbstractIndexer::class)]
#[UsesClass(TypoScriptService::class)]
final class FileIndexerTest extends TestCase
{
    private FileIndexer $subject;

    /**
     * Creates a TypoScriptService instance with stub collaborators, since these
     * tests exercise indexer behaviour, not TypoScript resolution itself.
     */
    private function createTypoScriptService(?ConfigurationManagerInterface $configurationManager = null): TypoScriptService
    {
        return new TypoScriptService(
            $configurationManager ?? self::createStub(ConfigurationManagerInterface::class),
            self::createStub(PageRepositoryInterface::class),
        );
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->createMock(ConnectionPool::class);

        $fileRepository           = new FileRepository($connectionPool);
        $fileCollectionRepository = $this->createMock(FileCollectionRepository::class);

        $this->subject = new FileIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->createMock(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
            $this->createMock(ResourceFactory::class),
            $fileCollectionRepository,
            $fileRepository,
            $this->createTypoScriptService(),
            new FileCollectionService(
                $fileCollectionRepository,
                $fileRepository,
                new CategoryRepository($connectionPool),
            ),
        );
    }

    /**
     * Tests that getTable() returns the expected database table name 'sys_file_metadata'.
     */
    #[Test]
    public function getTableReturnsSysFileMetadata(): void
    {
        self::assertSame('sys_file_metadata', $this->subject->getTable());
    }

    /**
     * Tests that withIndexingService() returns a new instance (immutable pattern)
     * while the original instance remains unchanged.
     */
    #[Test]
    public function withIndexingServiceReturnsNewInstance(): void
    {
        $indexingService = $this->createMock(IndexingService::class);
        $clone           = $this->subject->withIndexingService($indexingService);

        self::assertNotSame($this->subject, $clone);
        self::assertInstanceOf(IndexerInterface::class, $clone);
    }

    /**
     * Tests that withExcludeHiddenPages() returns a new instance (immutable pattern)
     * while the original instance remains unchanged.
     */
    #[Test]
    public function withExcludeHiddenPagesReturnsNewInstance(): void
    {
        $clone = $this->subject->withExcludeHiddenPages(true);

        self::assertNotSame($this->subject, $clone);
        self::assertInstanceOf(IndexerInterface::class, $clone);
    }

    /**
     * Tests that enqueueOne() throws a RuntimeException when called
     * without a configured indexing service.
     */
    #[Test]
    public function enqueueOneThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->enqueueOne(1);
    }

    /**
     * Tests that dequeueOne() throws a RuntimeException when called
     * without a configured indexing service.
     */
    #[Test]
    public function dequeueOneThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->dequeueOne(1);
    }

    /**
     * Tests that dequeueAll() throws a RuntimeException when called
     * without a configured indexing service.
     */
    #[Test]
    public function dequeueAllThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->dequeueAll();
    }

    /**
     * Tests that enqueueMultiple() throws a RuntimeException when called
     * without a configured indexing service.
     */
    #[Test]
    public function enqueueMultipleThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->enqueueMultiple([1, 2]);
    }

    /**
     * Tests that enqueueAll() throws a RuntimeException when called
     * without a configured indexing service.
     */
    #[Test]
    public function enqueueAllThrowsExceptionWithoutIndexingService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $this->subject->enqueueAll();
    }

    /**
     * Creates a FileIndexer for testing. Each optional collaborator not given
     * falls back to an unconfigured mock, the site finder is always one. The
     * page, file and category repositories, the TypoScript service and the
     * file collection service are real instances built on the connection
     * pool, the file collection repository and the configuration manager.
     *
     * @param QueueItemRepository|null           $queueItemRepository      The queue item repository to use
     * @param SearchEngineFactory|null           $searchEngineFactory      The search engine factory to use
     * @param DocumentBuilder|null               $documentBuilder          The document builder to use
     * @param ConnectionPool|null                $connectionPool           The connection pool shared by all repositories
     * @param ResourceFactory|null               $resourceFactory          The resource factory resolving the files
     * @param FileCollectionRepository|null      $fileCollectionRepository The file collection repository to use
     * @param ConfigurationManagerInterface|null $configurationManager     The configuration manager providing the TypoScript
     *
     * @return FileIndexer The file indexer under test
     */
    private function createConfiguredSubject(
        ?QueueItemRepository $queueItemRepository = null,
        ?SearchEngineFactory $searchEngineFactory = null,
        ?DocumentBuilder $documentBuilder = null,
        ?ConnectionPool $connectionPool = null,
        ?ResourceFactory $resourceFactory = null,
        ?FileCollectionRepository $fileCollectionRepository = null,
        ?ConfigurationManagerInterface $configurationManager = null,
    ): FileIndexer {
        $connectionPool ??= $this->createMock(ConnectionPool::class);
        $fileCollectionRepository ??= $this->createMock(FileCollectionRepository::class);
        $fileRepository = new FileRepository($connectionPool);

        return new FileIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $searchEngineFactory ?? $this->createMock(SearchEngineFactory::class),
            $queueItemRepository ?? $this->createMock(QueueItemRepository::class),
            $documentBuilder ?? $this->createMock(DocumentBuilder::class),
            $resourceFactory ?? $this->createMock(ResourceFactory::class),
            $fileCollectionRepository,
            $fileRepository,
            $this->createTypoScriptService($configurationManager),
            new FileCollectionService(
                $fileCollectionRepository,
                $fileRepository,
                new CategoryRepository($connectionPool),
            ),
        );
    }

    /**
     * Tests that dequeueOne() delegates to the QueueItemRepository with
     * the correct table name, record UID array and service UID.
     */
    #[Test]
    public function dequeueOneCallsRepositoryWithCorrectParameters(): void
    {
        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('deleteByTableAndRecordUIDs')
            ->with('sys_file_metadata', [42], 7);

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock
            ->method('getUid')
            ->willReturn(7);

        $result = $this->createConfiguredSubject(queueItemRepository: $queueItemRepositoryMock)
            ->withIndexingService($indexingServiceMock)
            ->dequeueOne(42);

        self::assertInstanceOf(IndexerInterface::class, $result);
    }

    /**
     * Tests that dequeueMultiple() delegates to the QueueItemRepository with
     * the correct table name, record UID array and service UID.
     */
    #[Test]
    public function dequeueMultipleCallsRepositoryWithCorrectParameters(): void
    {
        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('deleteByTableAndRecordUIDs')
            ->with('sys_file_metadata', [1, 2, 3], 7);

        $indexingServiceMock = $this->createMock(IndexingService::class);
        $indexingServiceMock
            ->method('getUid')
            ->willReturn(7);

        $result = $this->createConfiguredSubject(queueItemRepository: $queueItemRepositoryMock)
            ->withIndexingService($indexingServiceMock)
            ->dequeueMultiple([1, 2, 3]);

        self::assertInstanceOf(IndexerInterface::class, $result);
    }

    /**
     * Creates a query builder stub for the metadata-to-file lookup of the file
     * indexer, returning the given result. The named parameters are left to
     * the caller.
     *
     * @param Result $metadataQueryResult The result of the metadata lookup
     *
     * @return QueryBuilder&Stub The query builder stub
     */
    private function createMetadataQueryBuilder(Result $metadataQueryResult): QueryBuilder&Stub
    {
        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn(self::createStub(ExpressionBuilder::class));
        $queryBuilder->method('executeQuery')->willReturn($metadataQueryResult);

        return $queryBuilder;
    }

    /**
     * Creates a file stub. With the folder '/Dokumente/' and the extension
     * 'pdf' it passes every eligibility check of the file indexer and lies in
     * the folder of a recursive folder collection.
     *
     * @param int    $fileUid          The UID of the file
     * @param int    $metadataUid      The UID of the file's metadata record
     * @param string $folderIdentifier The identifier of the file's folder
     * @param string $extension        The file extension
     *
     * @return File The file stub
     */
    private function createFileStub(
        int $fileUid,
        int $metadataUid,
        string $folderIdentifier,
        string $extension,
    ): File {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);

        $parentFolder = self::createStub(Folder::class);
        $parentFolder->method('getIdentifier')->willReturn($folderIdentifier);

        $metaData = self::createStub(MetaDataAspect::class);
        $metaData
            ->method('get')
            ->willReturn([
                'uid'       => $metadataUid,
                'no_search' => 0,
            ]);
        $metaData
            ->method('offsetGet')
            ->willReturnMap([['uid', $metadataUid]]);

        $file = self::createStub(File::class);
        $file->method('getUid')->willReturn($fileUid);
        $file->method('isIndexed')->willReturn(true);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getMetaData')->willReturn($metaData);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getIdentifier')->willReturn($folderIdentifier . 'file-' . $fileUid . '.' . $extension);
        $file->method('getParentFolder')->willReturn($parentFolder);

        return $file;
    }

    /**
     * Tests that enqueueMultiple() queues exactly the eligible records among
     * the given file metadata records, each through the same checks as
     * enqueueOne(), and returns how many were queued, instead of queueing
     * every file of the indexing service's file collections. A workspace
     * version or translation resolving to an already collected file is queued
     * only once, after removing that file's pending queue item.
     */
    #[Test]
    public function enqueueMultipleQueuesOnlyTheGivenEligibleRecords(): void
    {
        // Metadata 5 and 7 belong to the existing files 105 and 107, metadata 6 to no file.
        // Metadata 8 is a workspace version of metadata 5, it resolves to file 105 as well.
        // Metadata 9 belongs to file 109 outside the service's collection folder, metadata 10
        // to file 110 with an extension the indexer does not accept.
        $fileUidsByMetadataUid = [5 => 105, 7 => 107, 9 => 109, 10 => 110];
        $lookedUpMetadataUids  = [];

        $metadataQueryResult = self::createStub(Result::class);
        $metadataQueryResult
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(105, false, 107, 105, 109, 110);

        $queryBuilder = $this->createMetadataQueryBuilder($metadataQueryResult);
        $queryBuilder
            ->method('createNamedParameter')
            ->willReturnCallback(
                static function (int $metadataUid) use (&$lookedUpMetadataUids): string {
                    $lookedUpMetadataUids[] = $metadataUid;

                    return ':dcValue1';
                },
            );

        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $resourceFactory = self::createStub(ResourceFactory::class);
        $resourceFactory
            ->method('retrieveFileOrFolderObject')
            ->willReturnCallback(
                fn (string $fileUid): File => $this->createFileStub(
                    (int) $fileUid,
                    (int) array_search((int) $fileUid, $fileUidsByMetadataUid, true),
                    ((int) $fileUid === 109) ? '/Andere/' : '/Dokumente/',
                    ((int) $fileUid === 110) ? 'txt' : 'pdf',
                ),
            );

        $fileCollectionRepositoryMock = $this->createMock(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->expects(self::never())
            ->method('findAllByCollectionUids');
        $fileCollectionRepositoryMock
            ->method('getCollectionDataByIds')
            ->willReturn([
                [
                    'uid'               => 1,
                    'type'              => 'folder',
                    'folder_identifier' => '1:/Dokumente/',
                    'recursive'         => 1,
                    'category'          => 0,
                ],
            ]);

        $queuedRecordUids = [];
        $queueCalls       = [];

        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->expects(self::never())
            ->method('insert');
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('deleteByTableAndRecordUIDs')
            ->with(
                'sys_file_metadata',
                [5, 7],
                3,
            )
            ->willReturnCallback(
                static function () use (&$queueCalls): int {
                    $queueCalls[] = 'delete';

                    return 2;
                },
            );
        $queueItemRepositoryMock
            ->expects(self::once())
            ->method('bulkInsert')
            ->willReturnCallback(
                static function (array $records) use (&$queuedRecordUids, &$queueCalls): int {
                    $queueCalls[] = 'insert';

                    foreach ($records as $record) {
                        $queuedRecordUids[] = $record['record_uid'];
                    }

                    return count($records);
                },
            );

        $configurationManager = self::createStub(ConfigurationManagerInterface::class);
        $configurationManager
            ->method('getConfiguration')
            ->willReturn([
                'module.' => [
                    'tx_typo3searchalgolia.' => [
                        'indexer.' => [
                            'sys_file_metadata.' => [
                                'extensions' => 'pdf',
                            ],
                        ],
                    ],
                ],
            ]);

        $indexingService = self::createStub(IndexingService::class);
        $indexingService->method('getUid')->willReturn(3);
        $indexingService->method('getFileCollections')->willReturn('1');

        $subject = $this->createConfiguredSubject(
            queueItemRepository: $queueItemRepositoryMock,
            connectionPool: $connectionPool,
            resourceFactory: $resourceFactory,
            fileCollectionRepository: $fileCollectionRepositoryMock,
            configurationManager: $configurationManager,
        );

        $queuedCount = $subject
            ->withIndexingService($indexingService)
            ->enqueueMultiple([5, 6, 7, 8, 9, 10]);

        self::assertSame(2, $queuedCount);
        self::assertSame([5, 6, 7, 8, 9, 10], $lookedUpMetadataUids);
        self::assertSame([5, 7], $queuedRecordUids);
        self::assertSame(['delete', 'insert'], $queueCalls);
    }

    /**
     * Tests that enqueueMultiple() leaves the queue untouched when none of the
     * given records is eligible, instead of removing every pending file queue
     * item of the indexing service with an empty list of record UIDs.
     */
    #[Test]
    public function enqueueMultipleLeavesTheQueueUntouchedWithoutEligibleRecords(): void
    {
        $metadataQueryResult = self::createStub(Result::class);
        $metadataQueryResult
            ->method('fetchOne')
            ->willReturn(false);

        $queryBuilder = $this->createMetadataQueryBuilder($metadataQueryResult);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);

        $queueItemRepositoryMock = $this->createMock(QueueItemRepository::class);
        $queueItemRepositoryMock->expects(self::never())->method('deleteByTableAndRecordUIDs');
        $queueItemRepositoryMock->expects(self::never())->method('bulkInsert');
        $queueItemRepositoryMock->expects(self::never())->method('insert');

        $subject = $this->createConfiguredSubject(
            queueItemRepository: $queueItemRepositoryMock,
            connectionPool: $connectionPool,
        );

        self::assertSame(
            0,
            $subject
                ->withIndexingService(self::createStub(IndexingService::class))
                ->enqueueMultiple([6]),
        );
    }

    /**
     * Tests that dequeueAll() delegates to the QueueItemRepository
     * by calling deleteByIndexingService() with the configured service.
     */
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

    /**
     * Tests the full indexRecord() happy path: retrieves the search engine,
     * opens the index, builds and updates the document, commits and closes.
     */
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

    /**
     * Tests that indexRecord() returns false and skips document building
     * when the search engine factory returns null.
     */
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

    /**
     * Tests that indexRecord() returns false when documentUpdate() fails,
     * but still calls indexCommit() and indexClose() for cleanup.
     */
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

    /**
     * Tests that withIndexingService() properly sets the service on the cloned
     * instance, allowing dequeueOne() to execute without RuntimeException.
     */
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

    /**
     * Tests that withExcludeHiddenPages() sets the correct boolean value
     * on the cloned instance via reflection inspection.
     */
    #[Test]
    public function withExcludeHiddenPagesSetsValueOnClone(): void
    {
        $clone = $this->subject->withExcludeHiddenPages(true);

        $reflection = new ReflectionProperty($clone, 'excludeHiddenPages');

        self::assertTrue($reflection->getValue($clone));

        $cloneFalse = $this->subject->withExcludeHiddenPages(false);

        self::assertFalse($reflection->getValue($cloneFalse));
    }

    /**
     * Creates a TypoScriptService whose getAllowedFileExtensions() genuinely
     * resolves (through the real TypoScript-parsing code path) to the given
     * extensions, unlike createTypoScriptService() above which never
     * populates the configuration manager and is only used by tests that
     * never reach that method.
     *
     * @param string[] $allowedFileExtensions
     */
    private function createTypoScriptServiceWithAllowedFileExtensions(array $allowedFileExtensions): TypoScriptService
    {
        $configurationManagerMock = self::createStub(ConfigurationManagerInterface::class);
        $configurationManagerMock
            ->method('getConfiguration')
            ->willReturn([
                'module.' => [
                    'tx_typo3searchalgolia.' => [
                        'indexer.' => [
                            'sys_file_metadata.' => [
                                'extensions' => implode(
                                    ',',
                                    $allowedFileExtensions,
                                ),
                            ],
                        ],
                    ],
                ],
            ]);

        return new TypoScriptService(
            $configurationManagerMock,
            self::createStub(PageRepositoryInterface::class),
        );
    }

    /**
     * Creates a File mock that FileEligibilityTrait::isEligible() accepts:
     * indexed, an allowed extension, and metadata carrying 'no_search' => 0
     * (so isEligible() never triggers its GeneralUtility::makeInstance()
     * metadata-reload branch, which a plain mock cannot satisfy).
     *
     * $tstamp defaults to $metadataUid when omitted, giving each mocked file
     * a distinct, deterministic sort key without every call site having to
     * name one explicitly; pass an explicit value to construct a genuine
     * tstamp tie between two or more mocks (see
     * findRecordUidsInScopeWithALimitOrdersByTstampWithUidTieBreak()).
     */
    private function createEligibleFileMock(int $metadataUid, string $extension, ?int $tstamp = null): File
    {
        $tstamp ??= $metadataUid;

        $metaDataAspectMock = self::createStub(MetaDataAspect::class);
        $metaDataAspectMock
            ->method('get')
            ->willReturn([
                'uid'       => $metadataUid,
                'no_search' => 0,
                'tstamp'    => $tstamp,
            ]);
        // FileIndexer::initQueueItemRecords() only ever reads the 'uid' and
        // 'tstamp' offsets, so a fixed map (rather than a with() argument
        // matcher, deprecated on test stubs as of PHPUnit 12 and removed in
        // 13) is sufficient here.
        $metaDataAspectMock
            ->method('offsetGet')
            ->willReturnMap([
                ['uid', $metadataUid],
                ['tstamp', $tstamp],
            ]);

        $fileMock = self::createStub(File::class);
        $fileMock->method('isIndexed')->willReturn(true);
        $fileMock->method('getExtension')->willReturn($extension);
        $fileMock->method('getMetaData')->willReturn($metaDataAspectMock);

        return $fileMock;
    }

    /**
     * Creates a FileIndexer with stubbed collaborators for the
     * findRecordUidsInScope() test scenarios below: allowed file extensions
     * fixed to 'pdf' (via createTypoScriptServiceWithAllowedFileExtensions())
     * and every other collaborator a bare self::createStub(), matching the
     * exact wiring every one of those tests needs. The FileCollectionRepository
     * varies between call sites (it feeds both the FileIndexer constructor
     * and the FileCollectionService it builds internally), mirroring this
     * class's own createConfiguredSubject() helper pattern above. The
     * QueueItemRepository stays optional, defaulting to a bare stub, and
     * only needs overriding by call sites that observe what enqueueAll()/
     * enqueueMultiple() actually pass to bulkInsert().
     */
    private function createStubbedSubject(
        FileCollectionRepository $fileCollectionRepositoryMock,
        ?QueueItemRepository $queueItemRepositoryMock = null,
    ): FileIndexer {
        $connectionPool = self::createStub(ConnectionPool::class);
        $fileRepository = new FileRepository($connectionPool);

        return new FileIndexer(
            $connectionPool,
            self::createStub(SiteFinder::class),
            new PageRepository($connectionPool),
            self::createStub(SearchEngineFactory::class),
            $queueItemRepositoryMock ?? self::createStub(QueueItemRepository::class),
            self::createStub(DocumentBuilder::class),
            self::createStub(ResourceFactory::class),
            $fileCollectionRepositoryMock,
            $fileRepository,
            $this->createTypoScriptServiceWithAllowedFileExtensions(['pdf']),
            new FileCollectionService(
                $fileCollectionRepositoryMock,
                $fileRepository,
                new CategoryRepository($connectionPool),
            ),
        );
    }

    /**
     * Verifies the cap in FileIndexer::initQueueItemRecords() actually caps:
     * five eligible files across the file collection, a limit of two,
     * exactly two record UIDs must come back. Since capping now orders
     * candidates by tstamp descending (record_uid descending as a
     * deterministic tie-break) before applying the limit (see that method's
     * docblock), each mock's default tstamp equal to its own metadataUid
     * makes the result fully deterministic too, asserted here as an exact,
     * ordered identity (the two highest metadataUids/tstamps win). The
     * dedicated tie-break scenario below
     * (findRecordUidsInScopeWithALimitOrdersByTstampWithUidTieBreak())
     * additionally proves the uid DESC tie-break itself, using files that
     * share the exact same tstamp.
     */
    #[Test]
    public function findRecordUidsInScopeAppliesTheLimitAcrossFileCollectionItems(): void
    {
        $collection = new StaticFileCollectionTestSubject();

        $eligibleMetadataUids = [101, 102, 103, 104, 105];

        foreach ($eligibleMetadataUids as $metadataUid) {
            $collection->add($this->createEligibleFileMock($metadataUid, 'pdf'));
        }

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');

        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $recordUids = $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope(2);

        self::assertSame([105, 104], $recordUids);
    }

    /**
     * Proves initQueueItemRecords() actually materializes candidates from
     * EVERY configured file collection before sorting/capping, not just the
     * first one it happens to iterate.
     *
     * A real indexing service can configure MULTIPLE file collections (see
     * getFileCollections()'s comma-separated list handling), and every other
     * capping test in this class stubs findAllByCollectionUids() to return
     * exactly ONE collection, so none of them would notice a regression that
     * reintroduces an early "break 2" exit inside the outer
     * "foreach ($collections as $collection)" loop in initQueueItemRecords()
     * once the limit is reached, silently dropping every candidate from any
     * collection processed AFTER the first one.
     *
     * Here two collections are stubbed: the first ("A") holds three
     * lower-tstamp files, already enough on its own to reach the limit of
     * two; the second ("B") holds the single file with the HIGHEST tstamp
     * of all four eligible candidates. A regressed early-exit-per-collection
     * implementation would fill the two-item cap entirely from collection A
     * before ever visiting collection B, so file 604 (tstamp 604, the
     * highest) would never even be considered, let alone included. The
     * fixed implementation materializes all four candidates across both
     * collections first, then sorts by tstamp descending, so 604 must win
     * the top slot.
     */
    #[Test]
    public function findRecordUidsInScopeAppliesTheLimitAcrossMultipleFileCollections(): void
    {
        $collectionA = new StaticFileCollectionTestSubject();

        foreach ([601, 602, 603] as $metadataUid) {
            $collectionA->add($this->createEligibleFileMock($metadataUid, 'pdf'));
        }

        $collectionB = new StaticFileCollectionTestSubject();
        $collectionB->add($this->createEligibleFileMock(604, 'pdf'));

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collectionA, $collectionB]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1,2');

        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $recordUids = $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope(2);

        self::assertSame([604, 603], $recordUids);
    }

    /**
     * Proves the uid DESC tie-break FileIndexer::initQueueItemRecords()
     * documents (see that method's docblock) is actually load-bearing: three
     * eligible files share the EXACT SAME tstamp, so only the tie-break can
     * deterministically decide which two of the three make it under a
     * limit of two. Without it, the outcome would depend on whatever order
     * file collection iteration happens to produce.
     */
    #[Test]
    public function findRecordUidsInScopeWithALimitOrdersByTstampWithUidTieBreak(): void
    {
        $collection = new StaticFileCollectionTestSubject();

        $collection->add($this->createEligibleFileMock(201, 'pdf', 500));
        $collection->add($this->createEligibleFileMock(202, 'pdf', 500));
        $collection->add($this->createEligibleFileMock(203, 'pdf', 500));

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');

        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $recordUids = $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope(2);

        self::assertSame([203, 202], $recordUids);
    }

    /**
     * Proves the non-truncating case (limit exceeds eligible count), which
     * we expect to be the common case since AttributeOverviewModuleController::SCOPE_RECORD_LIMIT
     * is 200 and typical file collections are assumed to be smaller than
     * that (not verified against production data), so uasort() still runs
     * but array_slice() is a no-op. All three eligible files must come
     * back, fully sorted by tstamp descending, proving the non-truncating
     * case does not accidentally drop or misorder items.
     */
    #[Test]
    public function findRecordUidsInScopeWithALimitExceedingTheEligibleCountReturnsAllSorted(): void
    {
        $collection = new StaticFileCollectionTestSubject();

        $eligibleMetadataUids = [301, 302, 303];

        foreach ($eligibleMetadataUids as $metadataUid) {
            $collection->add($this->createEligibleFileMock($metadataUid, 'pdf'));
        }

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');

        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $recordUids = $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope(10);

        self::assertSame([303, 302, 301], $recordUids);
    }

    /**
     * Proves the boundary case where the eligible-file count exactly equals
     * $limit, the classic off-by-one zone: an accidental $limit - 1 in the
     * slice would only surface here. Both eligible files must come back.
     */
    #[Test]
    public function findRecordUidsInScopeWithALimitEqualToTheEligibleCountReturnsAll(): void
    {
        $collection = new StaticFileCollectionTestSubject();

        $collection->add($this->createEligibleFileMock(401, 'pdf'));
        $collection->add($this->createEligibleFileMock(402, 'pdf'));

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');

        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $recordUids = $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope(2);

        self::assertSame([402, 401], $recordUids);
    }

    /**
     * Proves an empty eligible-files list with a positive $limit returns
     * cleanly, no error or warning, when no file in any configured
     * collection passes the eligibility check (here: wrong extension).
     */
    #[Test]
    public function findRecordUidsInScopeWithNoEligibleFilesReturnsEmptyArray(): void
    {
        $collection = new StaticFileCollectionTestSubject();
        $collection->add($this->createEligibleFileMock(501, 'jpg'));

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');

        // Only 'pdf' is allowed (see createStubbedSubject()), so the sole
        // 'jpg' file is never eligible.
        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $recordUids = $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope(10);

        self::assertSame([], $recordUids);
    }

    /**
     * Proves two things findRecordUidsInScope() cannot, since it strips
     * every field except 'record_uid' before returning:
     *
     * 1. enqueueAll() calls initQueueItemRecords() via its real production
     *    entry point, with queueItemRecordLimit at its default of 0. All
     *    three eligible files must come back uncapped, proving that default
     *    genuinely means "unbounded" and was not accidentally capped to
     *    some other value.
     * 2. Each queued record carries the full, correct shape
     *    (table_name/record_uid/service_uid/changed/priority), not just a
     *    record_uid, since the real indexing queue table needs all five
     *    columns to route this record back to the right table and
     *    indexing service on the next run. 'changed' is deliberately given
     *    a tstamp distinct from its own record_uid (99999 vs. 701), so this
     *    assertion cannot pass by 'changed' accidentally coinciding with
     *    record_uid, it must genuinely come from the file's own metadata
     *    tstamp.
     */
    #[Test]
    public function enqueueAllPreparesTheFullRecordShapeForEveryEligibleFileUncapped(): void
    {
        $collection = new StaticFileCollectionTestSubject();
        $collection->add($this->createEligibleFileMock(701, 'pdf', 99999));
        $collection->add($this->createEligibleFileMock(702, 'pdf'));
        $collection->add($this->createEligibleFileMock(703, 'pdf'));

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');
        $indexingServiceMock->method('getUid')->willReturn(9);

        $capturedRecords = [];

        $queueItemRepositoryMock = self::createStub(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->method('bulkInsert')
            ->willReturnCallback(static function (array $records) use (&$capturedRecords): int {
                $capturedRecords = $records;

                return count($records);
            });

        $indexer = $this->createStubbedSubject(
            $fileCollectionRepositoryMock,
            $queueItemRepositoryMock,
        );

        $insertedCount = $indexer
            ->withIndexingService($indexingServiceMock)
            ->enqueueAll();

        self::assertSame(
            3,
            $insertedCount,
            'enqueueAll() must queue every eligible file uncapped, not fall back to some accidental default cap.',
        );
        self::assertCount(
            3,
            $capturedRecords,
            'all three eligible files (701, 702, 703) must be queued uncapped.',
        );

        self::assertSame(
            [
                'table_name'  => 'sys_file_metadata',
                'record_uid'  => 701,
                'service_uid' => 9,
                'changed'     => 99999,
                'priority'    => 0,
            ],
            $capturedRecords[0],
        );
    }

    /**
     * Proves the configured file collection IDs are parsed with empty
     * segments removed: a collections config of '1,,2' (a stray empty
     * segment, e.g. from a trailing comma) must resolve to exactly [1, 2],
     * not [1, 0, 2].
     */
    #[Test]
    public function initQueueItemRecordsParsesCollectionIdsWithEmptySegmentsRemoved(): void
    {
        // A mock with expects(self::once()), not a bare stub, so this test
        // fails loudly (not silently pass with zero assertions executed)
        // if findAllByCollectionUids() were ever skipped.
        $fileCollectionRepositoryMock = $this->createMock(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->expects(self::once())
            ->method('findAllByCollectionUids')
            ->with(self::identicalTo([1, 2]))
            ->willReturn([]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1,,2');

        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope();
    }

    /**
     * Proves an ineligible file does not stop the scan of the remaining
     * files in the same collection: an eligible file listed AFTER an
     * ineligible one in iteration order must still be found.
     */
    #[Test]
    public function findRecordUidsInScopeSkipsAnIneligibleFileWithoutStoppingTheScan(): void
    {
        $collection = new StaticFileCollectionTestSubject();
        $collection->add($this->createEligibleFileMock(801, 'jpg'));
        $collection->add($this->createEligibleFileMock(802, 'pdf'));

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');

        // Only 'pdf' is allowed (see createStubbedSubject()), so 801 (jpg)
        // is skipped, but 802 (pdf), listed right after it, must still be
        // found rather than the scan stopping at the first ineligible file.
        $indexer = $this->createStubbedSubject($fileCollectionRepositoryMock);

        $recordUids = $indexer
            ->withIndexingService($indexingServiceMock)
            ->findRecordUidsInScope();

        self::assertSame([802], $recordUids);
    }

    /**
     * Proves the service UID falls back to exactly 0, not some other
     * placeholder, when the indexing service's own UID is not yet
     * available (e.g. a not-yet-persisted IndexingService, whose uid is
     * still null).
     */
    #[Test]
    public function initQueueItemRecordsFallsBackToZeroServiceUidWhenIndexingServiceHasNoUidYet(): void
    {
        $collection = new StaticFileCollectionTestSubject();
        $collection->add($this->createEligibleFileMock(901, 'pdf'));

        $fileCollectionRepositoryMock = self::createStub(FileCollectionRepository::class);
        $fileCollectionRepositoryMock
            ->method('findAllByCollectionUids')
            ->willReturn([$collection]);

        // Deliberately not stubbing getUid(), so it returns its default
        // null, matching a not-yet-persisted IndexingService.
        $indexingServiceMock = self::createStub(IndexingService::class);
        $indexingServiceMock->method('getFileCollections')->willReturn('1');

        $capturedRecords = [];

        $queueItemRepositoryMock = self::createStub(QueueItemRepository::class);
        $queueItemRepositoryMock
            ->method('bulkInsert')
            ->willReturnCallback(static function (array $records) use (&$capturedRecords): int {
                $capturedRecords = $records;

                return count($records);
            });

        $indexer = $this->createStubbedSubject(
            $fileCollectionRepositoryMock,
            $queueItemRepositoryMock,
        );

        $indexer
            ->withIndexingService($indexingServiceMock)
            ->enqueueAll();

        self::assertSame(0, $capturedRecords[0]['service_uid']);
    }
}
