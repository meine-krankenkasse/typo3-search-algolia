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
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Command\Fixtures\ArrayQueryResult;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

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
class IndexQueueWorkerCommandTest extends TestCase
{
    private MockObject&PersistenceManagerInterface $persistenceManagerMock;

    private MockObject&Registry $registryMock;

    private MockObject&ConnectionPool $connectionPoolMock;

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
        $this->connectionPoolMock            = $this->createMock(ConnectionPool::class);
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
     * Tests that getProgress() returns 0.0 when no progress value
     * has been stored in the TYPO3 registry yet.
     */
    #[Test]
    public function getProgressReturnsZeroWhenNoProgressStored(): void
    {
        $this->registryMock
            ->method('get')
            ->with(Constants::EXTENSION_NAME, 'index-queue-worker-progress')
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
            ->with(Constants::EXTENSION_NAME, 'index-queue-worker-progress')
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
            ->with(Constants::EXTENSION_NAME, 'index-queue-worker-progress')
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
     * Configures the connection pool mock to return the given record (or
     * false) for a query against the given table for the given record UID.
     *
     * @param string                     $tableName The table name the mocked query builder should respond for
     * @param int                        $recordUid The record UID the mocked query builder should respond for
     * @param array<string, mixed>|false $record    The record the query should resolve to
     */
    private function mockRecordQuery(string $tableName, int $recordUid, array|false $record): void
    {
        $expressionBuilderMock = $this->createMock(ExpressionBuilder::class);
        $expressionBuilderMock
            ->method('eq')
            ->with('uid', $recordUid)
            ->willReturn('uid = ' . $recordUid);

        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturn($record);

        $queryBuilderMock = self::createStub(QueryBuilder::class);
        $queryBuilderMock
            ->method('select')
            ->willReturn($queryBuilderMock);
        $queryBuilderMock
            ->method('from')
            ->willReturn($queryBuilderMock);
        $queryBuilderMock
            ->method('where')
            ->willReturn($queryBuilderMock);
        $queryBuilderMock
            ->method('expr')
            ->willReturn($expressionBuilderMock);
        $queryBuilderMock
            ->method('executeQuery')
            ->willReturn($resultMock);

        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->with($tableName)
            ->willReturn($queryBuilderMock);
    }

    /**
     * Builds a QueueItem test fixture with the given table name, record UID
     * and indexing service UID.
     *
     * @param string $tableName  The database table name of the record to index
     * @param int    $recordUid  The record UID
     * @param int    $serviceUid The indexing service UID
     */
    private function createQueueItem(string $tableName, int $recordUid, int $serviceUid): QueueItem
    {
        return (new QueueItem())
            ->setTableName($tableName)
            ->setRecordUid($recordUid)
            ->setServiceUid($serviceUid);
    }

    /**
     * Tests that a queue item is removed from the queue after it has been
     * indexed successfully, which is the normal, non-error path.
     */
    #[Test]
    public function indexItemsRemovesQueueItemAfterSuccessfulIndexing(): void
    {
        $queueItem = $this->createQueueItem('sys_file_metadata', 8754, 2);

        $this->mockRecordQuery('sys_file_metadata', 8754, ['uid' => 8754]);

        $this->queueItemRepositoryMock
            ->method('findAllLimited')
            ->willReturn(new ArrayQueryResult([$queueItem]));

        $indexingServiceMock = self::createStub(IndexingService::class);
        $this->indexingServiceRepositoryMock
            ->method('findByUid')
            ->with(2)
            ->willReturn($indexingServiceMock);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('indexRecord')
            ->willReturn(true);
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->with('sys_file_metadata')
            ->willReturn($indexerMock);

        $this->queueItemRepositoryMock
            ->expects(self::once())
            ->method('remove')
            ->with($queueItem);
        $this->persistenceManagerMock->expects(self::once())->method('persistAll');

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute([]);
    }

    /**
     * Tests that a queue item whose record exceeds the search engine's
     * record size limit is removed from the queue and logged, instead of
     * being left in the queue where it would fail again on every future run.
     */
    #[Test]
    public function indexItemsRemovesAndLogsQueueItemWhenRecordExceedsSizeLimit(): void
    {
        $queueItem = $this->createQueueItem('sys_file_metadata', 8754, 2);

        $this->mockRecordQuery('sys_file_metadata', 8754, ['uid' => 8754]);

        $this->queueItemRepositoryMock
            ->method('findAllLimited')
            ->willReturn(new ArrayQueryResult([$queueItem]));

        $indexingServiceMock = self::createStub(IndexingService::class);
        $this->indexingServiceRepositoryMock
            ->method('findByUid')
            ->with(2)
            ->willReturn($indexingServiceMock);

        $indexerMock = self::createStub(IndexerInterface::class);
        $indexerMock
            ->method('indexRecord')
            ->willThrowException(new BadRequestException('Record is too big.'));
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->with('sys_file_metadata')
            ->willReturn($indexerMock);

        $this->queueItemRepositoryMock
            ->expects(self::once())
            ->method('remove')
            ->with($queueItem);
        $this->persistenceManagerMock->expects(self::once())->method('persistAll');

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
                ])
            );

        $command = $this->createCommand();
        $command->setLogger($loggerMock);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
    }

    /**
     * Tests that a BadRequestException whose message does not indicate an
     * oversized record is not swallowed, since it may signal a different,
     * unexpected problem that should not be silently ignored.
     */
    #[Test]
    public function indexItemsRethrowsBadRequestExceptionForOtherReasons(): void
    {
        $queueItem = $this->createQueueItem('sys_file_metadata', 8754, 2);

        $this->mockRecordQuery('sys_file_metadata', 8754, ['uid' => 8754]);

        $this->queueItemRepositoryMock
            ->method('findAllLimited')
            ->willReturn(new ArrayQueryResult([$queueItem]));

        $indexingServiceMock = self::createStub(IndexingService::class);
        $this->indexingServiceRepositoryMock
            ->method('findByUid')
            ->with(2)
            ->willReturn($indexingServiceMock);

        $indexerMock = self::createStub(IndexerInterface::class);
        $indexerMock
            ->method('indexRecord')
            ->willThrowException(new BadRequestException('Invalid API key.'));
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->with('sys_file_metadata')
            ->willReturn($indexerMock);

        $this->queueItemRepositoryMock->expects(self::never())->method('remove');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('Invalid API key.', '/') . '/');

        $commandTester = new CommandTester($this->createCommand());
        $commandTester->execute([]);
    }
}
