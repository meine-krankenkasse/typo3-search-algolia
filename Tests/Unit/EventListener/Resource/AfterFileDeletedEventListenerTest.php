<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener\Resource;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\FileHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AbstractAfterFileEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileDeletedEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Unit tests for AfterFileDeletedEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AbstractAfterFileEventListener::class)]
#[CoversClass(AfterFileDeletedEventListener::class)]
#[UsesClass(DataHandlerRecordDeleteEvent::class)]
class AfterFileDeletedEventListenerTest extends TestCase
{
    /**
     * Tests that invoking the listener dispatches a DataHandlerRecordDeleteEvent when
     * the file handler returns a valid metadata UID (456). Verifies the dispatched event
     * has the table set to "sys_file_metadata" and the record UID set to 456.
     */
    #[Test]
    public function invokeDispatchesDeleteEventWhenMetadataUidIsValid(): void
    {
        $fileMock = $this->createMock(FileInterface::class);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->with($fileMock)
            ->willReturn(456);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (DataHandlerRecordDeleteEvent $event): bool => $event->getTable() === 'sys_file_metadata'
                    && $event->getRecordUid() === 456
            ));

        $fileDeletedEvent = new AfterFileDeletedEvent($fileMock);

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileDeletedEvent);
    }

    /**
     * Tests that invoking the listener does not dispatch any event when the file
     * handler returns false for the metadata UID. Verifies that the event dispatcher's
     * dispatch method is never called when no valid metadata UID is available.
     */
    #[Test]
    public function invokeDoesNotDispatchEventWhenMetadataUidIsFalse(): void
    {
        $fileMock = $this->createMock(FileInterface::class);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->with($fileMock)
            ->willReturn(false);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::never())
            ->method('dispatch');

        $fileDeletedEvent = new AfterFileDeletedEvent($fileMock);

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileDeletedEvent);
    }

    /**
     * Tests that invoking the listener returns early without attempting to get the
     * metadata UID or dispatch any event when the file is already marked as deleted.
     * Verifies that both getMetadataUid() and dispatch() are never called for a
     * deleted File instance.
     */
    #[Test]
    public function invokeReturnsEarlyWhenFileIsAlreadyDeleted(): void
    {
        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('isDeleted')
            ->willReturn(true);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->expects(self::never())
            ->method('getMetadataUid');

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::never())
            ->method('dispatch');

        $fileDeletedEvent = new AfterFileDeletedEvent($fileMock);

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileDeletedEvent);
    }
}
