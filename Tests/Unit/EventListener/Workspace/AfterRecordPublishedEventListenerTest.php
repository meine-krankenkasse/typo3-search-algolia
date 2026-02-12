<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener\Workspace;

use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Workspace\AfterRecordPublishedEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;

/**
 * Unit tests for AfterRecordPublishedEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AfterRecordPublishedEventListener::class)]
class AfterRecordPublishedEventListenerTest extends TestCase
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

        $listener = new AfterRecordPublishedEventListener($eventDispatcherMock);

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

        $listener = new AfterRecordPublishedEventListener($eventDispatcherMock);
        $listener($event);
    }
}
