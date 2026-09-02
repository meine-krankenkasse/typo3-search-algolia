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
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\Tokenizer\TokenizerInterface;
use TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

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
    private function createTypoScriptService(): TypoScriptService
    {
        return new TypoScriptService(
            self::createStub(ConfigurationManagerInterface::class),
            new TypoScriptStringFactory(
                self::createStub(ContainerInterface::class),
                self::createStub(TokenizerInterface::class),
            ),
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
     * Creates a FileIndexer with properly configured mocks for happy-path testing.
     */
    private function createConfiguredSubject(
        ?QueueItemRepository $queueItemRepository = null,
        ?SearchEngineFactory $searchEngineFactory = null,
        ?DocumentBuilder $documentBuilder = null,
    ): FileIndexer {
        $connectionPool           = $this->createMock(ConnectionPool::class);
        $fileCollectionRepository = $this->createMock(FileCollectionRepository::class);
        $fileRepository           = new FileRepository($connectionPool);

        return new FileIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $searchEngineFactory ?? $this->createMock(SearchEngineFactory::class),
            $queueItemRepository ?? $this->createMock(QueueItemRepository::class),
            $documentBuilder ?? $this->createMock(DocumentBuilder::class),
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
            new TypoScriptStringFactory(
                self::createStub(ContainerInterface::class),
                self::createStub(TokenizerInterface::class),
            ),
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
     *    entry point, with no $limit argument, relying entirely on the
     *    parameter's own default. All three eligible files must come back
     *    uncapped, proving that default genuinely means "unbounded" and
     *    was not accidentally capped to some other value.
     * 2. Each queued record carries the full, correct shape
     *    (table_name/record_uid/service_uid/changed/priority), not just a
     *    record_uid, since the real indexing queue table needs all five
     *    columns to route this record back to the right table and
     *    indexing service on the next run.
     */
    #[Test]
    public function enqueueAllPreparesTheFullRecordShapeForEveryEligibleFileUncapped(): void
    {
        $collection = new StaticFileCollectionTestSubject();
        $collection->add($this->createEligibleFileMock(701, 'pdf'));
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
                'changed'     => 0,
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
