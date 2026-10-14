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
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AbstractAfterFileEventListener;
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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unit tests for RecordUpdateEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordUpdateEventListener::class)]
#[UsesClass(AbstractAfterFileEventListener::class)]
#[UsesClass(DataHandlerRecordUpdateEvent::class)]
final class RecordUpdateEventListenerTest extends TestCase
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

        // The listener reads the time from the context singleton
        GeneralUtility::purgeInstances();

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
     * Tests that the listener hands an enabled record (not hidden, not
     * deleted) over to the record handler to be put into the queue again,
     * and does not remove the record through the removal of a disabled record.
     */
    #[Test]
    public function invokeUpdatesRecordInQueueWhenEnabled(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(['hidden' => 0, 'deleted' => 0, 'no_search' => 0]);

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('updateRecordsInQueue')
            ->with(1, 'pages', [42], true);

        $this->recordHandlerMock
            ->expects(self::never())
            ->method('removeRecords');

        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42));
    }

    /**
     * Tests that the listener asks the record handler not to remove a
     * document for a record that was just created, because none exists.
     */
    #[Test]
    public function invokeAsksForNoRemovalWhenTheRecordWasCreated(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(['hidden' => 0, 'deleted' => 0, 'no_search' => 0]);

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('updateRecordsInQueue')
            ->with(1, 'pages', [42], false);

        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42, [], true));
    }

    /**
     * Tests that the listener removes a disabled record (hidden) from the
     * queue and from the search index for every matching indexer, and
     * processes the content elements of the page.
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

        $this->recordHandlerMock
            ->expects(self::never())
            ->method('updateRecordsInQueue');

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('removeRecords')
            ->with(1, 'pages', [42]);

        // Pages table also triggers processContentElementsOfPage
        $this->recordHandlerMock
            ->expects(self::once())
            ->method('processContentElementsOfPage')
            ->with(42, true);

        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42));
    }

    /**
     * Tests that a page outside of its publication window is handled like a disabled
     * page. The indexer does not accept it, so its document and the documents of its
     * content elements are removed, and a page inside of its window is queued.
     *
     * @param int  $starttime The start time of the page
     * @param int  $endtime   The end time of the page
     * @param bool $removed   Whether the page and its content are removed
     */
    #[Test]
    #[DataProvider('publicationWindows')]
    public function invokeRemovesAPageOutsideOfItsPublicationWindow(
        int $starttime,
        int $endtime,
        bool $removed,
    ): void {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturn(
                [
                    'hidden'    => 0,
                    'deleted'   => 0,
                    'no_search' => 0,
                    'starttime' => $starttime,
                    'endtime'   => $endtime,
                ]
            );

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->recordHandlerMock
            ->expects($removed ? self::never() : self::once())
            ->method('updateRecordsInQueue');

        $this->recordHandlerMock
            ->expects($removed ? self::once() : self::never())
            ->method('removeRecords');

        $this->recordHandlerMock
            ->expects(self::once())
            ->method('processContentElementsOfPage')
            ->with(42, $removed);

        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42));
    }

    /**
     * Provides publication windows of a page.
     *
     * @return iterable<string, array{int, int, bool}>
     */
    public static function publicationWindows(): iterable
    {
        yield 'no window' => [0, 0, false];
        yield 'window that holds the present' => [1000, 4102444800, false];
        yield 'window that has not started' => [4102444800, 0, true];
        yield 'window that has ended' => [0, 1000, true];
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
        // Second call: getPageRecord for the state of the subpages, see decideSubtree()
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 0, 'extendToSubpages' => 1];
                }

                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100, 101]);

        $updated = [];

        $this->recordHandlerMock
            ->method('updateRecordsInQueue')
            ->willReturnCallback(
                static function (int $rootPageId, string $table, array $uids, bool $removeRejected = true) use (&$updated): void {
                    $updated[] = [$rootPageId, $table, $uids, $removeRejected];
                }
            );

        // The page is shown again and extends its state, so the subpages are decided
        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 0]);

        $this->createListener()($event);

        self::assertContains([1, 'pages', [42], true], $updated);
        self::assertContains([1, 'pages', [100, 101], false], $updated, 'The subpages are updated as a batch, without removal');
    }

    /**
     * Tests that hiding a page that extends the hidden state to its subpages
     * removes the documents of the subpages and of their content elements, as
     * the frontend hides them as well.
     */
    #[Test]
    public function invokeRemovesTheSubpagesWhenAPageThatExtendsToSubpagesIsHidden(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 1, 'extendToSubpages' => 1];
                }

                // The page is hidden, so is every subpage
                return ['hidden' => 1, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100, 101]);

        $removedRecords = [];

        $this->recordHandlerMock
            ->method('removeRecords')
            ->willReturnCallback(
                static function (int $rootPageId, string $table, array $uids) use (&$removedRecords): void {
                    $removedRecords[] = [$rootPageId, $table, $uids];
                }
            );

        $this->recordRepositoryMock
            ->method('findUidsByPids')
            ->with('tt_content', [100, 101])
            ->willReturn([500, 501]);

        $removed = [];

        $this->recordHandlerMock
            ->method('processContentElementsOfPage')
            ->willReturnCallback(
                static function (int $pageId, bool $remove, ?array $uids = null) use (&$removed): void {
                    $removed[] = [$pageId, $remove, $uids];
                }
            );

        // Only the hidden field changed, extendToSubpages is set already
        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 1]);

        $this->createListener()($event);

        self::assertContains([1, 'pages', [42]], $removedRecords);
        self::assertContains([1, 'pages', [100, 101]], $removedRecords, 'The subpages are removed as a batch');

        // The content of the page, and the content of all subpages in one call
        self::assertSame([[42, true, null], [42, true, [500, 501]]], $removed);
    }

    /**
     * Tests that a page that extends its state and has ended removes its subpages
     * like a hidden page does, because the frontend does not reach them either.
     */
    #[Test]
    public function invokeRemovesTheSubpagesWhenAPageThatExtendsToSubpagesHasEnded(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 0, 'starttime' => 0, 'endtime' => 1000, 'extendToSubpages' => 1];
                }

                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0, 'starttime' => 0, 'endtime' => 1000];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100, 101]);

        $removedRecords = [];

        $this->recordHandlerMock
            ->method('removeRecords')
            ->willReturnCallback(
                static function (int $rootPageId, string $table, array $uids) use (&$removedRecords): void {
                    $removedRecords[] = [$rootPageId, $table, $uids];
                }
            );

        // Only the end time changed, extendToSubpages is set already
        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42, ['endtime' => 1000]));

        self::assertContains([1, 'pages', [100, 101]], $removedRecords, 'The subpages are removed as a batch');
    }

    /**
     * Tests that the walk through the subpages of a hidden page that extends to
     * them does not stop at a subpage that is hidden itself, because the frontend
     * hides everything below the page.
     */
    #[Test]
    public function invokeWalksThroughHiddenSubpagesWhenAPageThatExtendsToSubpagesIsHidden(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 1, 'extendToSubpages' => 1];
                }

                return ['hidden' => 1, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        // Hidden subpages are not excluded from the walk
        $this->pageRepositoryMock
            ->expects(self::once())
            ->method('getPageIdsRecursive')
            ->with([42], self::anything(), false, false)
            ->willReturn([100, 101]);

        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 1]));
    }

    /**
     * Tests that a subpage is decided as enabled when the page itself is only
     * excluded from the search, because that flag belongs to the page and does
     * not extend to its subpages.
     */
    #[Test]
    public function invokeKeepsTheSubpagesWhenAPageExcludedFromTheSearchIsShownAgain(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 0, 'extendToSubpages' => 1];
                }

                // Not hidden, but excluded from the search
                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 1];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100, 101]);

        $updated = [];

        $this->recordHandlerMock
            ->method('updateRecordsInQueue')
            ->willReturnCallback(
                static function (int $rootPageId, string $table, array $uids, bool $removeRejected = true) use (&$updated): void {
                    $updated[] = [$rootPageId, $table, $uids, $removeRejected];
                }
            );

        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 0]));

        self::assertContains([1, 'pages', [100, 101], false], $updated, 'The subpages are queued again and not removed');
    }

    /**
     * Tests that a published page that extends to its subpages decides them, so
     * the publish that shows the page again queues them as the form of the page does.
     */
    #[Test]
    public function invokeDecidesTheSubpagesOfAPublishedPageThatExtendsToThem(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 0, 'extendToSubpages' => 1];
                }

                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->expects(self::once())
            ->method('getPageIdsRecursive')
            ->willReturn([100]);

        $updated = [];

        $this->recordHandlerMock
            ->method('updateRecordsInQueue')
            ->willReturnCallback(
                static function (int $rootPageId, string $table, array $uids, bool $removeRejected) use (&$updated): void {
                    $updated[] = [$rootPageId, $table, $uids, $removeRejected];
                }
            );

        // The publish names no field
        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42));

        self::assertContains([1, 'pages', [100], false], $updated, 'The subpages are queued again and not removed');
    }

    /**
     * Tests that the publish of a workspace change that switched the extension off on
     * a page that stays hidden queues the subpages again. The publish names no field,
     * and the subpages were removed while the page extended its state.
     */
    #[Test]
    public function invokeQueuesTheSubpagesOfAPublishedHiddenPageThatNoLongerExtends(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 1, 'extendToSubpages' => 0];
                }

                return ['hidden' => 1, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->expects(self::once())
            ->method('getPageIdsRecursive')
            ->willReturn([100]);

        $updated = [];

        $this->recordHandlerMock
            ->method('updateRecordsInQueue')
            ->willReturnCallback(
                static function (int $rootPageId, string $table, array $uids, bool $removeRejected) use (&$updated): void {
                    $updated[] = [$rootPageId, $table, $uids, $removeRejected];
                }
            );

        // The publish names no field
        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42));

        self::assertContains([1, 'pages', [100], false], $updated, 'The subpages are queued again');
    }

    /**
     * Tests that the extension of the hidden state being switched off on a hidden
     * page queues the subpages again, because the frontend shows them again.
     */
    #[Test]
    public function invokeQueuesTheSubpagesWhenTheExtensionOfAHiddenPageIsSwitchedOff(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 1, 'extendToSubpages' => 0];
                }

                return ['hidden' => 1, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->method('getPageIdsRecursive')
            ->willReturn([100, 101]);

        $updated = [];

        $this->recordHandlerMock
            ->method('updateRecordsInQueue')
            ->willReturnCallback(
                static function (int $rootPageId, string $table, array $uids, bool $removeRejected = true) use (&$updated): void {
                    $updated[] = [$rootPageId, $table, $uids, $removeRejected];
                }
            );

        $this->createListener()(new DataHandlerRecordUpdateEvent('pages', 42, ['extendToSubpages' => 0]));

        self::assertContains([1, 'pages', [100, 101], false], $updated, 'The subpages are queued again and not removed');
    }

    /**
     * Tests that hiding a page that does not extend to its subpages leaves the
     * subpages alone, because they stay visible in the frontend.
     */
    #[Test]
    public function invokeLeavesTheSubpagesWhenAHiddenPageDoesNotExtendToThem(): void
    {
        $this->pageRepositoryMock
            ->method('getPageRecord')
            ->willReturnCallback(function (string $table, int $uid, string $fields = '*', bool $respectRestrictions = true): array {
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 1, 'extendToSubpages' => 0];
                }

                return ['hidden' => 1, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->expects(self::never())
            ->method('getPageIdsRecursive');

        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['hidden' => 1]);

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
                if ($fields === 'hidden, starttime, endtime, extendToSubpages') {
                    return ['hidden' => 0, 'extendToSubpages' => 0];
                }

                return ['hidden' => 0, 'deleted' => 0, 'no_search' => 0];
            });

        $this->recordHandlerMock
            ->method('getRecordRootPageId')
            ->willReturn(1);

        $this->pageRepositoryMock
            ->expects(self::never())
            ->method('getPageIdsRecursive');

        // No hidden/extendToSubpages changes
        $event = new DataHandlerRecordUpdateEvent('pages', 42, ['title' => 'New title']);

        $this->createListener()($event);
    }
}
