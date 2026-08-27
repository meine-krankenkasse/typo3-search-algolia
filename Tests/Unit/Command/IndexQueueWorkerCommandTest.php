<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Command;

use Doctrine\DBAL\Result;
use MeineKrankenkasse\Typo3SearchAlgolia\Command\IndexQueueWorkerCommand;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\CliFallbackTypoScriptUnreadableException;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusServiceInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
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

/**
 * Unit tests for IndexQueueWorkerCommand.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(IndexQueueWorkerCommand::class)]
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
     * Builds a QueryResultInterface stub over the given queue items,
     * iterable exactly once (matching the single foreach in indexItems()).
     *
     * @param QueueItem[] $items
     *
     * @return MockObject&QueryResultInterface<int, QueueItem>
     */
    private function createQueueItemsResult(array $items): MockObject&QueryResultInterface
    {
        $queryResultMock = $this->createMock(QueryResultInterface::class);
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
        $expressionBuilderMock = $this->createMock(ExpressionBuilder::class);
        $expressionBuilderMock
            ->method('eq')
            ->willReturn('1 = 1');

        $resultMock = $this->createMock(Result::class);
        $resultMock
            ->method('fetchAssociative')
            ->willReturn(['uid' => 1]);

        $queryBuilderMock = $this->createMock(QueryBuilder::class);
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
     * Tests that a CliFallbackTypoScriptUnreadableException raised by one queue
     * item's indexer does not abort the whole batch: the failing item is logged
     * and skipped (left in the queue for retry), while the next item in the same
     * run is still processed to completion (removed from the queue, persisted).
     */
    #[Test]
    public function indexItemsSkipsAFailingItemAndProcessesTheNextOne(): void
    {
        $failingItem = (new QueueItem())
            ->setTableName('pages')
            ->setRecordUid(1)
            ->setServiceUid(1);
        $succeedingItem = (new QueueItem())
            ->setTableName('pages')
            ->setRecordUid(2)
            ->setServiceUid(1);

        $this->queueItemRepositoryMock
            ->method('findAllLimited')
            ->willReturn($this->createQueueItemsResult([$failingItem, $succeedingItem]));

        $this->stubFetchableRecord();

        $indexingService = new IndexingService();

        $this->indexingServiceRepositoryMock
            ->method('findByUid')
            ->with(1)
            ->willReturn($indexingService);

        $failingIndexerMock = $this->createMock(IndexerInterface::class);
        $failingIndexerMock
            ->method('indexRecord')
            ->willThrowException(
                new CliFallbackTypoScriptUnreadableException('Unable to read the bundled TypoScript setup.')
            );

        $succeedingIndexerMock = $this->createMock(IndexerInterface::class);
        $succeedingIndexerMock
            ->method('indexRecord')
            ->willReturn(true);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->with('pages')
            ->willReturnOnConsecutiveCalls($failingIndexerMock, $succeedingIndexerMock);

        // The failing item must NOT be removed from the queue (it is retried on
        // the next run); only the succeeding one is.
        $this->queueItemRepositoryMock
            ->expects(self::once())
            ->method('remove')
            ->with($succeedingItem);

        $this->persistenceManagerMock
            ->expects(self::once())
            ->method('persistAll');

        // The batch must run to completion (not abort after the failing item).
        $this->queueStatusServiceMock
            ->expects(self::once())
            ->method('setLastExecutionTime');

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock
            ->expects(self::once())
            ->method('error')
            ->with(
                'Skipping queue item due to an environment-level failure: {message}',
                self::callback(
                    static fn (array $context): bool => ($context['tableName'] ?? null) === 'pages'
                        && ($context['recordUid'] ?? null) === 1
                        && ($context['message'] ?? null) === 'Unable to read the bundled TypoScript setup.'
                )
            );

        $command = $this->createCommand();
        $command->setLogger($loggerMock);
        // Normally supplied via the Services.yaml console.command tag; set
        // explicitly here since the command is constructed directly, not
        // resolved through the DI container.
        $command->setName('mkk:queue:index:worker');

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--documentsToIndex' => 2]);
    }
}
