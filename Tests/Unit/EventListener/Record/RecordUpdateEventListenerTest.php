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
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AbstractFileEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
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
#[UsesClass(AbstractFileEventListener::class)]
#[UsesClass(DataHandlerRecordUpdateEvent::class)]
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

    /**
     * Tests that the listener enqueues the record when the page record
     * is enabled (not hidden, not deleted) and has matching indexers.
     */
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

    /**
     * Tests that the listener dequeues the record and processes content
     * elements when the page record is disabled (hidden).
     */
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

    /**
     * Tests that the listener processes the parent page when a tt_content
     * record is updated.
     */
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

    /**
     * Tests that the listener does not process a parent page when
     * the updated record is not a content element.
     */
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

    /**
     * Tests that the listener processes content elements of a page
     * when the page record itself is updated.
     */
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

    /**
     * Tests that the listener processes subpages recursively when the
     * hidden field changes with extendToSubpages enabled.
     */
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

    /**
     * Tests that the listener skips subpage processing when no
     * hidden or extendToSubpages field changes are present.
     */
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

    /**
     * Tests that the listener updates all subpages and their content
     * elements with the correct arguments when a page's visibility change
     * cascades down the tree, and that every configured indexing service
     * is processed, not just the first one yielded by the generator.
     */
    #[Test]
    public function invokeUpdatesSubpagesAndTheirContentElementsForAllIndexingServices(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, extendToSubpages') {
                    return ['hidden' => 0, 'extendToSubpages' => 0];
                }

                if ($uid === 100) {
                    return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
                }

                if ($uid === 101) {
                    return ['hidden' => 1, 'deleted' => 0, 'no_search' => 0];
                }

                // The event record itself (uid 42)
                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100, 101]);

        $indexerMock1 = $this->createMock(IndexerInterface::class);
        $indexerMock1
            ->expects(self::once())
            ->method('enqueueMultiple')
            ->with([100, 101]);

        $indexerMock2 = $this->createMock(IndexerInterface::class);
        $indexerMock2
            ->expects(self::once())
            ->method('enqueueMultiple')
            ->with([100, 101]);

        $indexingServiceMock1 = $this->createMock(IndexingService::class);
        $indexingServiceMock2 = $this->createMock(IndexingService::class);

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturnCallback(static function () use ($indexingServiceMock1, $indexerMock1, $indexingServiceMock2, $indexerMock2): Generator {
                yield $indexingServiceMock1 => $indexerMock1;
                yield $indexingServiceMock2 => $indexerMock2;
            });

        $this->recordHandlerMock
            ->expects(self::exactly(2))
            ->method('deleteRecords')
            ->with(self::anything(), self::anything(), 'pages', [100, 101], false);

        $processContentElementsCalls = [];
        $this->recordHandlerMock
            ->method('processContentElementsOfPage')
            ->willReturnCallback(function (int $pageId, bool $removeContentElements) use (&$processContentElementsCalls): void {
                $processContentElementsCalls[] = [$pageId, $removeContentElements];
            });

        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 0, 'extendToSubpages' => 0]);

        $this->createListener()($event);

        self::assertSame(
            [
                [42, false],
                [100, false],
                [101, true],
            ],
            $processContentElementsCalls,
        );
    }

    /**
     * Tests that subpages are dequeued (deleteRecords with removeFromIndex
     * true) but never re-enqueued when the parent page itself is disabled,
     * proving the `if ($isRecordEnabled)` guard around enqueueMultiple()
     * actually gates the call.
     */
    #[Test]
    public function invokeDoesNotEnqueueSubpagesWhenTheParentPageIsDisabled(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, extendToSubpages') {
                    return ['hidden' => 0, 'extendToSubpages' => 0];
                }

                // The event record itself (uid 42) is disabled (hidden)
                return ['hidden' => 1, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100]);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->expects(self::never())
            ->method('enqueueMultiple');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturnCallback(static function () use ($indexingServiceMock, $indexerMock): Generator {
                yield $indexingServiceMock => $indexerMock;
            });

        $this->recordHandlerMock
            ->expects(self::atLeastOnce())
            ->method('deleteRecords')
            ->with(self::anything(), self::anything(), 'pages', [100], true);

        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 0, 'extendToSubpages' => 0]);

        $this->createListener()($event);
    }

    /**
     * @return array<string, array{0: array<string, int>, 1: array<string, int>, 2: bool}>
     */
    public static function subpageUpdateRequiredBoundaryProvider(): array
    {
        return [
            'branch A: hidden=0 and extendToSubpages=0 changed together' => [
                ['hidden' => 0, 'extendToSubpages' => 0],
                ['hidden' => 0, 'extendToSubpages' => 0],
                true,
            ],
            'branch A: hidden changed to non-zero' => [
                ['hidden' => 0, 'extendToSubpages' => 0],
                ['hidden' => 1, 'extendToSubpages' => 0],
                false,
            ],
            'branch A: extendToSubpages changed to non-zero' => [
                ['hidden' => 0, 'extendToSubpages' => 0],
                ['hidden' => 0, 'extendToSubpages' => 1],
                false,
            ],
            'branch B: extendToSubpages enabled on the page, hidden changed to 0' => [
                ['hidden' => 0, 'extendToSubpages' => 1],
                ['hidden' => 0],
                true,
            ],
            'branch B: extendToSubpages not enabled on the page' => [
                ['hidden' => 0, 'extendToSubpages' => 0],
                ['hidden' => 0],
                false,
            ],
            'branch B: hidden changed to non-zero' => [
                ['hidden' => 0, 'extendToSubpages' => 1],
                ['hidden' => 1],
                false,
            ],
            'branch C: hidden enabled on the page, extendToSubpages changed to 0' => [
                ['hidden' => 1, 'extendToSubpages' => 0],
                ['extendToSubpages' => 0],
                true,
            ],
            'branch C: hidden not enabled on the page' => [
                ['hidden' => 0, 'extendToSubpages' => 0],
                ['extendToSubpages' => 0],
                false,
            ],
            'branch C: extendToSubpages changed to non-zero' => [
                ['hidden' => 1, 'extendToSubpages' => 0],
                ['extendToSubpages' => 1],
                false,
            ],
        ];
    }

    /**
     * Tests every boundary value of the three hidden/extendToSubpages
     * conditions in isSubpageUpdateRequired() by observing whether the
     * subpage tree lookup (getPageIdsRecursive) is triggered, without
     * reaching into the private method via reflection.
     */
    #[Test]
    #[DataProvider('subpageUpdateRequiredBoundaryProvider')]
    public function invokeTriggersSubpageLookupOnlyAtTheDocumentedBoundaryValues(
        array $record,
        array $updatedFields,
        bool $expectsSubpageLookup,
    ): void {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true) use ($record): array {
                if ($fields === 'hidden, extendToSubpages') {
                    return $record;
                }

                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->recordHandlerMock
            ->method('createIndexerGenerator')
            ->willReturnCallback(static function (): Generator {
                yield from [];
            });

        if ($expectsSubpageLookup) {
            // Returning [] short-circuits at the "if ($subPageIds !== [])"
            // guard, so no further subpage-cascade mocks are needed here,
            // this test only observes whether the lookup itself fires.
            $this->pageRepositoryMock
                ->expects(self::once())
                ->method('getPageIdsRecursive')
                ->willReturn([]);
        } else {
            $this->pageRepositoryMock
                ->expects(self::never())
                ->method('getPageIdsRecursive');
        }

        $event = new DataHandlerRecordUpdateEvent('pages', 42, $updatedFields);

        $this->createListener()($event);
    }
}
