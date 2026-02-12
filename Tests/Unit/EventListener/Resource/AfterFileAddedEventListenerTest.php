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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileAddedEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * Unit tests for AfterFileAddedEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AfterFileAddedEventListener::class)]
class AfterFileAddedEventListenerTest extends TestCase
{
    /**
     * Tests that invoking the listener dispatches a DataHandlerRecordUpdateEvent when
     * the file handler returns a valid metadata UID (123). Verifies the dispatched event
     * has the table set to "sys_file_metadata" and the record UID set to 123.
     */
    #[Test]
    public function invokeDispatchesUpdateEventWhenMetadataUidIsValid(): void
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
                static fn (DataHandlerRecordUpdateEvent $event): bool => $event->getTable() === 'sys_file_metadata'
                    && $event->getRecordUid() === 123
            ));

        $folderMock     = $this->createMock(Folder::class);
        $fileAddedEvent = new AfterFileAddedEvent($fileMock, $folderMock);

        $listener = new AfterFileAddedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileAddedEvent);
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

        $folderMock     = $this->createMock(Folder::class);
        $fileAddedEvent = new AfterFileAddedEvent($fileMock, $folderMock);

        $listener = new AfterFileAddedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileAddedEvent);
    }
}
