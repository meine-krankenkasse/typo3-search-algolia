<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Command;

use MeineKrankenkasse\Typo3SearchAlgolia\Command\IndexQueueWorkerCommand;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusServiceInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

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
}
