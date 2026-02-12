<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener\Record;

use Generator;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandlerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record\RecordUpdateEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RecordUpdateEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordUpdateEventListener::class)]
class RecordUpdateEventListenerTest extends TestCase
{
    private MockObject&RecordHandlerInterface $recordHandlerMock;

    private MockObject&RecordRepositoryInterface $recordRepositoryMock;

    private MockObject&PageRepositoryInterface $pageRepositoryMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->recordHandlerMock    = $this->createMock(RecordHandlerInterface::class);
        $this->recordRepositoryMock = $this->createMock(RecordRepositoryInterface::class);
        $this->pageRepositoryMock   = $this->createMock(PageRepositoryInterface::class);

        $GLOBALS['TCA']['pages']['ctrl']['enablecolumns']['disabled']      = 'hidden';
        $GLOBALS['TCA']['pages']['ctrl']['delete']                         = 'deleted';
        $GLOBALS['TCA']['tt_content']['ctrl']['enablecolumns']['disabled'] = 'hidden';
        $GLOBALS['TCA']['tt_content']['ctrl']['delete']                    = 'deleted';
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    private function createListener(): RecordUpdateEventListener
    {
        return new RecordUpdateEventListener(
            $this->recordHandlerMock,
            $this->recordRepositoryMock,
            $this->pageRepositoryMock,
        );
    }

    #[Test]
    public function invokeEnqueuesRecordWhenEnabled(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(['hidden' => 0, 'deleted' => 0, 'no_search' => 0]);

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::once())
            ->method('enqueueOne')
            ->with(42);

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $generator = (static function () use ($indexingServiceMock, $indexerMock): Generator {
            yield $indexingServiceMock => $indexerMock;
        })();

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturn($generator);

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('deleteRecord');

        $event = new DataHandlerRecordUpdateEvent('pages', 42);

        $this->createListener()($event);
    }

    #[Test]
    public function invokeDequeuesRecordWhenDisabled(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(['hidden' => 1, 'deleted' => 0, 'no_search' => 0]);

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::never())
            ->method('enqueueOne');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $generator = (static function () use ($indexingServiceMock, $indexerMock): Generator {
            yield $indexingServiceMock => $indexerMock;
        })();

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturn($generator);

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('deleteRecord');

        // Pages table also triggers processContentElementsOfPage
        $this->recordHandlerMock
            ->expects(self::once())
            ->method('processContentElementsOfPage')
            ->with(42, true);

        $event = new DataHandlerRecordUpdateEvent('pages', 42);

        $this->createListener()($event);
    }

    #[Test]
    public function invokeProcessesPageOfContentElementForTtContent(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(['hidden' => 0, 'deleted' => 0]);

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $indexerMock         = $this->createMock(IndexerInterface::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $generator = (static function () use ($indexingServiceMock, $indexerMock): Generator {
            yield $indexingServiceMock => $indexerMock;
        })();

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturn($generator);

        $this->recordRepositoryMock
            ->method('findPid')
            ->with('tt_content', 99)
            ->willReturn(10);

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('processPageOfContentElement')
            ->with(1, 10);

        $event = new DataHandlerRecordUpdateEvent('tt_content', 99);

        $this->createListener()($event);
    }

    #[Test]
    public function invokeDoesNotProcessPageForNonContentElements(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(['hidden' => 0, 'deleted' => 0, 'no_search' => 0]);

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $indexerMock         = $this->createMock(IndexerInterface::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $generator = (static function () use ($indexingServiceMock, $indexerMock): Generator {
            yield $indexingServiceMock => $indexerMock;
        })();

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturn($generator);

        $this->recordHandlerMock
            ->expects(self::never())
            ->method('processPageOfContentElement');

        $event = new DataHandlerRecordUpdateEvent('tx_news_domain_model_news', 42);

        $this->createListener()($event);
    }

    #[Test]
    public function invokeProcessesContentElementsWhenPageUpdated(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(['hidden' => 0, 'deleted' => 0, 'no_search' => 0]);

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $indexerMock         = $this->createMock(IndexerInterface::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $generator = (static function () use ($indexingServiceMock, $indexerMock): Generator {
            yield $indexingServiceMock => $indexerMock;
        })();

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturn($generator);

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('processContentElementsOfPage')
            ->with(42, false);

        $event = new DataHandlerRecordUpdateEvent('pages', 42);

        $this->createListener()($event);
    }

    #[Test]
    public function invokeProcessesSubpagesWhenHiddenFieldChanges(): void
    {
        // First call: getPageRecord for the event record itself
        // Second call: getPageRecord for isSubpageUpdateRequired
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, extendToSubpages') {
                    return ['hidden' => 1, 'extendToSubpages' => 1];
                }

                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $indexerMock         = $this->createMock(IndexerInterface::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);

        // The generator is consumed in processRecordUpdate, so we need a fresh one for processRecordUpdates
        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturnCallback(static function () use ($indexingServiceMock, $indexerMock): Generator {
                yield $indexingServiceMock => $indexerMock;
            });

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100, 101]);

        $this->recordHandlerMock
            ->expects(self::atLeastOnce())
            ->method('deleteRecord');

        // The event with hidden=0 and extendToSubpages=0 triggers subpage processing
        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 0, 'extendToSubpages' => 0]);

        $this->createListener()($event);
    }

    #[Test]
    public function invokeSkipsSubpageProcessingWhenNotRequired(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, extendToSubpages') {
                    return ['hidden' => 0, 'extendToSubpages' => 0];
                }

                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $indexerMock         = $this->createMock(IndexerInterface::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturnCallback(static function () use ($indexingServiceMock, $indexerMock): Generator {
                yield $indexingServiceMock => $indexerMock;
            });

        $this->pageRepositoryMock
            ->expects(self::never())
            ->method('getPageIdsRecursive');

        // No hidden/extendToSubpages changes
        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['title' => 'New title']);

        $this->createListener()($event);
    }
}
