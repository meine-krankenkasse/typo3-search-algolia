<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Command;

use Algolia\AlgoliaSearch\Exceptions\BadRequestException;
use Doctrine\DBAL\Result;
use MeineKrankenkasse\Typo3SearchAlgolia\Command\IndexQueueWorkerCommand;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusServiceInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

use function array_values;
use function count;
use function preg_quote;

/**
 * Unit tests for IndexQueueWorkerCommand.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(IndexQueueWorkerCommand::class)]
#[UsesClass(QueueItem::class)]
#[UsesClass(IndexingService::class)]
class IndexQueueWorkerCommandTest extends TestCase
{
    private MockObject&PersistenceManagerInterface $persistenceManagerMock;

    private MockObject&Registry $registryMock;

    private Stub&ConnectionPool $connectionPoolMock;

    private MockObject&QueueItemRepository $queueItemRepositoryMock;

    private MockObject&IndexingServiceRepository $indexingServiceRepositoryMock;

    private MockObject&QueueStatusServiceInterface $queueStatusServiceMock;

    private MockObject&IndexerFactory $indexerFactoryMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->persistenceManagerMock        = $this->createMock(PersistenceManagerInterface::class);
        $this->registryMock                  = $this->createMock(Registry::class);
        $this->connectionPoolMock            = self::createStub(ConnectionPool::class);
        $this->queueItemRepositoryMock       = $this->createMock(QueueItemRepository::class);
        $this->indexingServiceRepositoryMock = $this->createMock(IndexingServiceRepository::class);
        $this->queueStatusServiceMock        = $this->createMock(QueueStatusServiceInterface::class);
        $this->indexerFactoryMock            = $this->createMock(IndexerFactory::class);
    }

    private function createCommand(): IndexQueueWorkerCommand
    {
        return new IndexQueueWorkerCommand(
            $this->persistenceManagerMock,
            $this->registryMock,
            $this->connectionPoolMock,
            $this->queueItemRepositoryMock,
            $this->indexingServiceRepositoryMock,
            $this->queueStatusServiceMock,
            $this->indexerFactoryMock,
        );
    }

    /**
     * Builds a QueryResultInterface stub over the given queue items,
     * iterable exactly once (matching the single foreach in indexItems()).
     *
     * @param QueueItem[] $items
     *
     * @return Stub&QueryResultInterface<int, QueueItem>
     */
    private function createQueueItemsResult(array $items): Stub&QueryResultInterface
    {
        $queryResultMock = self::createStub(QueryResultInterface::class);
        $queryResultMock
            ->method('count')
            ->willReturn(count($items));

        $positions = array_values($items);
        $index     = 0;

        $queryResultMock
            ->method('rewind')
            ->willReturnCallback(static function () use (&$index): void {
                $index = 0;
            });
        $queryResultMock
            ->method('valid')
            ->willReturnCallback(static function () use (&$positions, &$index): bool {
                return isset($positions[$index]);
            });
        $queryResultMock
            ->method('current')
            ->willReturnCallback(static function () use (&$positions, &$index): QueueItem {
                return $positions[$index];
            });
        $queryResultMock
            ->method('key')
            ->willReturnCallback(static function () use (&$index): int {
                return $index;
            });
        $queryResultMock
            ->method('next')
            ->willReturnCallback(static function () use (&$index): void {
                ++$index;
            });

        return $queryResultMock;
    }

    /**
     * Stubs the ConnectionPool so fetchRecord() resolves any queue item to a
     * (content-irrelevant) record array, regardless of table/uid.
     */
    private function stubFetchableRecord(): void
    {
        $expressionBuilderMock = self::createStub(ExpressionBuilder::class);
        $expressionBuilderMock
            ->method('eq')
            ->willReturn('1 = 1');

        $resultMock = self::createStub(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturn(['uid' => 1]);

        $queryBuilderMock = self::createStub(QueryBuilder::class);
        $queryBuilderMock->method('select')->willReturnSelf();
        $queryBuilderMock->method('from')->willReturnSelf();
        $queryBuilderMock->method('where')->willReturnSelf();
        $queryBuilderMock->method('expr')->willReturn($expressionBuilderMock);
        $queryBuilderMock->method('executeQuery')->willReturn($resultMock);

        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($queryBuilderMock);
    }

    /**
     * Arranges a single queue item (table sys_file_metadata, record 8754,
     * indexing service 2) so that it is returned by findAllLimited() and
     * indexed via the given indexer, the setup every indexItems() test
     * needs before adding its own, distinct expectations.
     *
     * @param IndexerInterface $indexer The indexer to return for the table of the queue item
     *
     * @return QueueItem The arranged queue item, for tests that assert on its removal
     */
    private function arrangeSingleQueueItem(IndexerInterface $indexer): QueueItem
    {
        $queueItem = (new QueueItem())
            ->setTableName('sys_file_metadata')
            ->setRecordUid(8754)
            ->setServiceUid(2);

        $this->stubFetchableRecord();

        $this->queueItemRepositoryMock
            ->method('findAllLimited')
            ->willReturn($this->createQueueItemsResult([$queueItem]));

        $this->indexingServiceRepositoryMock
            ->method('findByUid')
            ->with(2)
            ->willReturn(new IndexingService());

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->with('sys_file_metadata')
            ->willReturn($indexer);

        return $queueItem;
    }

    /**
     * Runs the command for one batch, optionally with a logger.
     *
     * @param LoggerInterface|null $logger The logger to set on the command, none when omitted
     */
    private function runCommand(?LoggerInterface $logger = null): void
    {
        $command = $this->createCommand();

        if ($logger instanceof LoggerInterface) {
            $command->setLogger($logger);
        }

        // Normally supplied via the Services.yaml console.command tag
        $command->setName('mkk:queue:index:worker');

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--documentsToIndex' => 1]);
    }

    /**
     * Tests that getProgress() returns 0.0 when no progress value
     * has been stored in the TYPO3 registry yet.
     */
    #[Test]
    public function getProgressReturnsZeroWhenNoProgressStored(): void
    {
        $this->registryMock
            ->method('get')
            ->with(
                Constants::EXTENSION_NAME,
                'index-queue-worker-progress',
            )
            ->willReturn(null);

        $command = $this->createCommand();

        self::assertSame(0.0, $command->getProgress());
    }

    /**
     * Tests that getProgress() correctly scales the registry value (0.5)
     * to a percentage (50.0).
     */
    #[Test]
    public function getProgressReturnsScaledPercentage(): void
    {
        $this->registryMock
            ->method('get')
            ->with(
                Constants::EXTENSION_NAME,
                'index-queue-worker-progress',
            )
            ->willReturn(0.5);

        $command = $this->createCommand();

        self::assertSame(50.0, $command->getProgress());
    }

    /**
     * Tests that getProgress() returns 100.0 when the registry
     * contains a progress value of 1 (fully complete).
     */
    #[Test]
    public function getProgressReturnsHundredWhenComplete(): void
    {
        $this->registryMock
            ->method('get')
            ->with(
                Constants::EXTENSION_NAME,
                'index-queue-worker-progress',
            )
            ->willReturn(1);

        $command = $this->createCommand();

        self::assertSame(100.0, $command->getProgress());
    }

    /**
     * Tests that the command can be instantiated with all required
     * dependencies including the IndexerFactory.
     */
    #[Test]
    public function constructorAcceptsIndexerFactory(): void
    {
        $command = $this->createCommand();

        // The command should be created without errors when IndexerFactory is injected
        self::assertInstanceOf(IndexQueueWorkerCommand::class, $command);
    }

    /**
     * Tests that indexItems() persists its progress into the TYPO3
     * registry during a run, proving the write side of progress tracking
     * actually executes.
     */
    #[Test]
    public function indexItemsPersistsProgressToTheRegistry(): void
    {
        $item = (new QueueItem())
            ->setTableName('pages')
            ->setRecordUid(1)
            ->setServiceUid(1);

        $this->queueItemRepositoryMock
            ->method('findAllLimited')
            ->willReturn($this->createQueueItemsResult([$item]));

        $this->stubFetchableRecord();

        $indexingService = new IndexingService();

        $this->indexingServiceRepositoryMock
            ->method('findByUid')
            ->with(1)
            ->willReturn($indexingService);

        $indexerMock = self::createStub(IndexerInterface::class);
        $indexerMock
            ->method('indexRecord')
            ->willReturn(true);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->with('pages')
            ->willReturn($indexerMock);

        $this->registryMock
            ->expects(self::once())
            ->method('set')
            ->with(
                Constants::EXTENSION_NAME,
                'index-queue-worker-progress',
                self::isFloat(),
            );

        $command = $this->createCommand();
        $command->setName('mkk:queue:index:worker');

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--documentsToIndex' => 1]);
    }

    /**
     * Tests that a queue item is removed from the queue after it has been
     * indexed successfully, which is the normal, non-error path.
     */
    #[Test]
    public function indexItemsRemovesQueueItemAfterSuccessfulIndexing(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('indexRecord')
            ->willReturn(true);

        $queueItem = $this->arrangeSingleQueueItem($indexerMock);

        $this->queueItemRepositoryMock
            ->expects(self::once())
            ->method('remove')
            ->with($queueItem);

        $this->persistenceManagerMock
            ->expects(self::once())
            ->method('persistAll');

        $this->runCommand();
    }

    /**
     * Tests that a queue item whose record exceeds the search engine's
     * record size limit is removed from the queue and logged, instead of
     * being left in the queue where it would fail again on every future run.
     */
    #[Test]
    public function indexItemsRemovesAndLogsQueueItemWhenRecordExceedsSizeLimit(): void
    {
        $indexerStub = self::createStub(IndexerInterface::class);
        $indexerStub
            ->method('indexRecord')
            ->willThrowException(new BadRequestException('Record is too big.'));

        $queueItem = $this->arrangeSingleQueueItem($indexerStub);

        $this->queueItemRepositoryMock
            ->expects(self::once())
            ->method('remove')
            ->with($queueItem);

        $this->persistenceManagerMock
            ->expects(self::once())
            ->method('persistAll');

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::identicalTo('Record exceeds search engine size limit, removed from queue without indexing'),
                self::identicalTo([
                    'tableName' => 'sys_file_metadata',
                    'recordUid' => 8754,
                    'exception' => 'Record is too big.',
                ]),
            );

        $this->runCommand($loggerMock);
    }

    /**
     * Tests that a too-big-record queue item is still removed even when no
     * logger was ever set on the command, so the nullsafe logger call is
     * genuinely optional.
     */
    #[Test]
    public function indexItemsRemovesQueueItemWhenRecordExceedsSizeLimitWithoutLoggerSet(): void
    {
        $indexerStub = self::createStub(IndexerInterface::class);
        $indexerStub
            ->method('indexRecord')
            ->willThrowException(new BadRequestException('Record is too big.'));

        $queueItem = $this->arrangeSingleQueueItem($indexerStub);

        $this->queueItemRepositoryMock
            ->expects(self::once())
            ->method('remove')
            ->with($queueItem);

        $this->persistenceManagerMock
            ->expects(self::once())
            ->method('persistAll');

        $this->runCommand();
    }

    /**
     * Tests that a BadRequestException whose message does not indicate an
     * oversized record is not swallowed, since it may signal a different,
     * unexpected problem that should not be silently ignored.
     */
    #[Test]
    public function indexItemsRethrowsBadRequestExceptionForOtherReasons(): void
    {
        $indexerStub = self::createStub(IndexerInterface::class);
        $indexerStub
            ->method('indexRecord')
            ->willThrowException(new BadRequestException('Invalid API key.'));

        $this->arrangeSingleQueueItem($indexerStub);

        $this->queueItemRepositoryMock
            ->expects(self::never())
            ->method('remove');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('Invalid API key.', '/') . '/');

        $this->runCommand();
    }
}
