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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record\RecordDeleteEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;

/**
 * Functional tests for RecordDeleteEventListener.
 *
 * Tests the delete flow end-to-end with real DB queries for page record
 * lookup and root page resolution. Mocks IndexerFactory and SearchEngineFactory
 * to avoid external service calls.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordDeleteEventListener::class)]
final class RecordDeleteEventListenerTest extends AbstractFunctionalTestCase
{
    private MockObject&IndexerFactory $indexerFactoryMock;

    private MockObject&IndexerInterface $indexerMock;

    private RecordDeleteEventListener $subject;

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

        // Create indexer mock that can be returned by the factory
        $this->indexerMock = $this->createMock(IndexerInterface::class);
        $this->indexerMock
            ->method('getTable')
            ->willReturn('pages');
        $this->indexerMock
            ->method('withIndexingService')
            ->willReturn($this->indexerMock);

        $this->indexerFactoryMock = $this->createMock(IndexerFactory::class);

        // IndexingServiceRepository via DI uses Extbase persistence
        $indexingServiceRepository = $this->get(IndexingServiceRepository::class);

        $recordHandler = new RecordHandler(
            $this->createMock(SearchEngineFactory::class),
            $this->indexerFactoryMock,
            $pageRepository,
            $indexingServiceRepository,
            new ContentRepository($connectionPool),
        );

        $this->subject = new RecordDeleteEventListener(
            $recordHandler,
            new RecordRepository($connectionPool),
            $pageRepository,
        );
    }

    /**
     * Tests that the listener deletes a page record from the indexing
     * queue when a DataHandlerRecordDeleteEvent is dispatched.
     */
    #[Test]
    public function invokeDeletesPageFromQueue(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->expects(self::atLeastOnce())
            ->method('dequeueOne')
            ->with(2)
            ->willReturn($this->indexerMock);

        $event = new DataHandlerRecordDeleteEvent('pages', 2);

        ($this->subject)($event);
    }

    /**
     * Tests that the listener deletes a content element from the indexing
     * queue when a DataHandlerRecordDeleteEvent is dispatched for tt_content.
     */
    #[Test]
    public function invokeDeletesContentElementFromQueue(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('tt_content');
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);
        $indexerMock
            ->expects(self::atLeastOnce())
            ->method('dequeueOne')
            ->with(1)
            ->willReturn($indexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        $event = new DataHandlerRecordDeleteEvent('tt_content', 1);

        ($this->subject)($event);
    }

    /**
     * Tests that the listener throws a PageNotFoundException when the
     * delete event references a record that does not exist in the database.
     */
    #[Test]
    public function invokeThrowsExceptionForNonExistentRecord(): void
    {
        $this->expectException(PageNotFoundException::class);

        $event = new DataHandlerRecordDeleteEvent('pages', 999);

        ($this->subject)($event);
    }
}
