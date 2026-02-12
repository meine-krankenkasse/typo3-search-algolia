<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record\RecordMoveEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Functional tests for RecordMoveEventListener.
 *
 * Tests the move flow end-to-end with real DB queries for page record
 * lookup and root page resolution. Mocks IndexerFactory and SearchEngineFactory
 * to avoid external service calls.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordMoveEventListener::class)]
final class RecordMoveEventListenerTest extends AbstractFunctionalTestCase
{
    private MockObject&IndexerFactory $indexerFactoryMock;

    private MockObject&IndexerInterface $indexerMock;

    private RecordMoveEventListener $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();
        $pageRepository = new PageRepository($connectionPool);

        $this->indexerMock = $this->createMock(IndexerInterface::class);
        $this->indexerMock
            ->method('getTable')
            ->willReturn('pages');
        $this->indexerMock
            ->method('withIndexingService')
            ->willReturn($this->indexerMock);

        $this->indexerFactoryMock = $this->createMock(IndexerFactory::class);

        $indexingServiceRepository = $this->get(IndexingServiceRepository::class);

        $recordHandler = new RecordHandler(
            $this->createMock(SearchEngineFactory::class),
            $this->indexerFactoryMock,
            $pageRepository,
            $indexingServiceRepository,
            new ContentRepository($connectionPool),
        );

        $this->subject = new RecordMoveEventListener(
            $recordHandler,
            $pageRepository,
        );
    }

    /**
     * Tests that the listener dequeues and re-enqueues a page record
     * when a DataHandlerRecordMoveEvent is dispatched with a new target.
     */
    #[Test]
    public function invokeUpdatesRecordInQueueOnMove(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->expects(self::atLeastOnce())
            ->method('dequeueOne')
            ->with(2)
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->expects(self::atLeastOnce())
            ->method('enqueueOne')
            ->with(2);

        $event = new DataHandlerRecordMoveEvent('pages', 2, 3);
        $event->setPreviousPid(1);

        ($this->subject)($event);
    }

    /**
     * Tests that the listener does nothing when the target page ID
     * equals the previous page ID, indicating no actual move occurred.
     */
    #[Test]
    public function invokeDoesNothingWhenTargetEqualsPrevious(): void
    {
        $this->indexerFactoryMock
            ->expects(self::never())
            ->method('makeInstanceByType');

        $event = new DataHandlerRecordMoveEvent('pages', 2, 1);
        $event->setPreviousPid(1);

        ($this->subject)($event);
    }

    /**
     * Tests that the listener handles a move event correctly when the
     * previous page ID is null, treating it as a valid move operation.
     */
    #[Test]
    public function invokeHandlesMoveWithNullPreviousPid(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->method('dequeueOne')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->method('enqueueOne')
            ->willReturn(1);

        // previousPid is null by default, targetPid differs from null
        $event = new DataHandlerRecordMoveEvent('pages', 2, 3);

        // Should not throw - null !== 3, so move is processed
        ($this->subject)($event);

        self::assertTrue(true);
    }
}
