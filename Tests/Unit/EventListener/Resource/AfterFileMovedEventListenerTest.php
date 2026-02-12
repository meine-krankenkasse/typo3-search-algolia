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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AbstractAfterFileEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileMovedEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileMovedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * Unit tests for AfterFileMovedEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AbstractAfterFileEventListener::class)]
#[CoversClass(AfterFileMovedEventListener::class)]
#[UsesClass(DataHandlerRecordMoveEvent::class)]
#[UsesClass(DataHandlerRecordUpdateEvent::class)]
class AfterFileMovedEventListenerTest extends TestCase
{
    /**
     * Tests that invoking the listener dispatches a DataHandlerRecordMoveEvent when
     * the file handler returns a valid metadata UID (123). Verifies the dispatched event
     * has the table set to "sys_file_metadata", the record UID set to 123, and target PID set to 0.
     */
    #[Test]
    public function invokeDispatchesMoveEventWhenMetadataUidIsValid(): void
    {
        $fileMock = $this->createMock(FileInterface::class);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->with($fileMock)
            ->willReturn(123);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (DataHandlerRecordMoveEvent $event): bool => $event->getTable() === 'sys_file_metadata'
                    && $event->getRecordUid() === 123
                    && $event->getTargetPid() === 0
            ));

        $folderMock         = $this->createMock(Folder::class);
        $originalFolderMock = $this->createMock(Folder::class);
        $fileMovedEvent     = new AfterFileMovedEvent($fileMock, $folderMock, $originalFolderMock);

        $listener = new AfterFileMovedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileMovedEvent);
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

        $folderMock         = $this->createMock(Folder::class);
        $originalFolderMock = $this->createMock(Folder::class);
        $fileMovedEvent     = new AfterFileMovedEvent($fileMock, $folderMock, $originalFolderMock);

        $listener = new AfterFileMovedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileMovedEvent);
    }
}
