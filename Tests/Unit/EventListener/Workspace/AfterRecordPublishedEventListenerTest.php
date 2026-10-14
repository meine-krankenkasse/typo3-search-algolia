<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener\Workspace;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Workspace\AfterRecordPublishedEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Unit tests for AfterRecordPublishedEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AfterRecordPublishedEventListener::class)]
#[UsesClass(DataHandlerRecordUpdateEvent::class)]
final class AfterRecordPublishedEventListenerTest extends TestCase
{
    /**
     * Tests that the listener dispatches a DataHandlerRecordUpdateEvent when
     * the workspaces extension is loaded and the event has the expected methods.
     *
     * Note: We cannot truly test with ExtensionManagementUtility::isLoaded('workspaces')
     * returning true without the workspaces extension installed, so we test the
     * early return paths and the method_exists checks.
     */
    #[Test]
    public function invokeDoesNothingWhenWorkspacesNotLoaded(): void
    {
        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::never())
            ->method('dispatch');

        $event = new stdClass();

        $listener = new AfterRecordPublishedEventListener($eventDispatcherMock, self::createStub(PageRepositoryInterface::class));

        // This will return early because workspaces is not loaded
        $listener($event);
    }

    /**
     * Tests that the listener does nothing when the event doesn't have the expected methods,
     * even if workspaces would be loaded.
     */
    #[Test]
    public function invokeHandlesEventWithoutGetAffectedRecordMethod(): void
    {
        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::never())
            ->method('dispatch');

        // Event without the expected methods
        $event = new class {
            // No getTable() or getRecordId() methods
        };

        $listener = new AfterRecordPublishedEventListener($eventDispatcherMock, self::createStub(PageRepositoryInterface::class));
        $listener($event);
    }

    /**
     * Tests that the listener announces the update of a published record that
     * still exists.
     */
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function invokeAnnouncesTheUpdateOfAPublishedRecord(): void
    {
        $this->loadWorkspacesExtension();

        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository
            ->method('hasPageRecord')
            ->with('tt_content', 12)
            ->willReturn(true);

        $events = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(
                static function (object $event) use (&$events): object {
                    $events[] = $event;

                    return $event;
                }
            );

        $listener = new AfterRecordPublishedEventListener($eventDispatcher, $pageRepository);
        $listener($this->createPublishedEvent('tt_content', 12));

        self::assertCount(1, $events);
        self::assertInstanceOf(DataHandlerRecordUpdateEvent::class, $events[0]);
        self::assertSame('tt_content', $events[0]->getTable());
        self::assertSame(12, $events[0]->getRecordUid());
    }

    /**
     * Tests that the listener decides nothing for a published record that is
     * gone, because the publish of a workspace delete removes the live record
     * and the delete of it is announced on its own.
     */
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function invokeAnnouncesNothingForAPublishedRecordThatIsGone(): void
    {
        $this->loadWorkspacesExtension();

        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository
            ->method('hasPageRecord')
            ->willReturn(false);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects(self::never())
            ->method('dispatch');

        $listener = new AfterRecordPublishedEventListener($eventDispatcher, $pageRepository);
        $listener($this->createPublishedEvent('pages', 3));
    }

    /**
     * Makes the extension manager report the workspaces extension as loaded.
     *
     * @return void
     */
    private function loadWorkspacesExtension(): void
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager
            ->method('isPackageActive')
            ->willReturn(true);

        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    /**
     * Creates an event with the methods of the event of the workspaces
     * extension that the listener uses.
     *
     * @param string $table    The table of the published record
     * @param int    $recordId The UID of the published record
     *
     * @return object The event
     */
    private function createPublishedEvent(
        string $table,
        int $recordId,
    ): object {
        return new class($table, $recordId) {
            /**
             * @param string $table    The table of the published record
             * @param int    $recordId The UID of the published record
             */
            public function __construct(
                private readonly string $table,
                private readonly int $recordId,
            ) {
            }

            /**
             * Returns the table of the published record, like the workspaces event does.
             *
             * @return string
             */
            public function getTable(): string
            {
                return $this->table;
            }

            /**
             * Returns the UID of the published record, like the workspaces event does.
             *
             * @return int
             */
            public function getRecordId(): int
            {
                return $this->recordId;
            }
        };
    }
}
