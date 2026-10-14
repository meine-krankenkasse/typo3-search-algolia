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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
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
use TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;

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
#[UsesClass(DataHandlerRecordDeletedEvent::class)]
final class AfterFileDeletedEventListenerTest extends TestCase
{
    /**
     * Creates a file mock.
     *
     * @return FileInterface
     */
    private function createFileMock(): FileInterface
    {
        return $this->createMock(FileInterface::class);
    }

    /**
     * Tests that the listener dispatches a DataHandlerRecordDeleteEvent for the
     * metadata record before the file is deleted and nothing else, because
     * TYPO3 may still fail to delete the file.
     */
    #[Test]
    public function onBeforeDeletedDispatchesOnlyTheDeleteEvent(): void
    {
        $fileMock = $this->createFileMock();

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->with($fileMock)
            ->willReturn(456);

        $dispatched = [];

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(
                static function (object $event) use (&$dispatched): object {
                    $dispatched[] = $event;

                    return $event;
                }
            );

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener->onBeforeDeleted(new BeforeFileDeletedEvent($fileMock));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(DataHandlerRecordDeleteEvent::class, $dispatched[0]);
        self::assertSame('sys_file_metadata', $dispatched[0]->getTable());
        self::assertSame(456, $dispatched[0]->getRecordUid());
    }

    /**
     * Tests that the listener dispatches a DataHandlerRecordDeletedEvent for the
     * metadata record remembered before the deletion, once the file is deleted.
     * The file reports itself as deleted at that point, which does not matter.
     */
    #[Test]
    public function invokeDispatchesTheDeletedEventForTheRememberedMetadataRecord(): void
    {
        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('isDeleted')
            ->willReturn(true);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->willReturn(456);

        $dispatched = [];

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static function (object $event) use (&$dispatched): object {
                    $dispatched[] = $event;

                    return $event;
                }
            );

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener->onBeforeDeleted(new BeforeFileDeletedEvent($fileMock));
        $listener(new AfterFileDeletedEvent($fileMock));

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(DataHandlerRecordDeleteEvent::class, $dispatched[0]);
        self::assertInstanceOf(DataHandlerRecordDeletedEvent::class, $dispatched[1]);
        self::assertSame('sys_file_metadata', $dispatched[1]->getTable());
        self::assertSame(456, $dispatched[1]->getRecordUid());
    }

    /**
     * Tests that a deleted file whose metadata record was not remembered, for
     * example because it has none, dispatches nothing.
     */
    #[Test]
    public function invokeDispatchesNothingForAFileThatWasNotRemembered(): void
    {
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

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener(new AfterFileDeletedEvent($this->createFileMock()));
    }

    /**
     * Tests that a metadata record remembered for a file whose deletion failed
     * does not survive for a later deletion of the same object, which has no
     * metadata record any more.
     */
    #[Test]
    public function aStaleRememberedRecordIsDroppedWhenTheFileHasNoMetadataAnyMore(): void
    {
        $fileMock = $this->createFileMock();

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->willReturnOnConsecutiveCalls(456, false);

        $dispatched = [];

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static function (object $event) use (&$dispatched): object {
                    $dispatched[] = $event;

                    return $event;
                }
            );

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);

        // The first deletion failed, so the After event never came
        $listener->onBeforeDeleted(new BeforeFileDeletedEvent($fileMock));
        $listener->onBeforeDeleted(new BeforeFileDeletedEvent($fileMock));
        $listener(new AfterFileDeletedEvent($fileMock));

        // Only the delete event of the first deletion was dispatched
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(DataHandlerRecordDeleteEvent::class, $dispatched[0]);
    }

    /**
     * Tests that the listener remembers and dispatches nothing when the file
     * has no valid metadata record.
     */
    #[Test]
    public function onBeforeDeletedDispatchesNothingWhenMetadataUidIsFalse(): void
    {
        $fileMock = $this->createFileMock();

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->willReturn(false);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::never())
            ->method('dispatch');

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener->onBeforeDeleted(new BeforeFileDeletedEvent($fileMock));
        $listener(new AfterFileDeletedEvent($fileMock));
    }

    /**
     * Tests that the deletion of a processed file announces nothing. TYPO3
     * deletes the processed files of a file whenever it is added or replaced,
     * and the metadata lookup of a processed file resolves to the metadata of
     * its original, which still exists.
     */
    #[Test]
    public function aProcessedFileAnnouncesNothing(): void
    {
        $processedFileMock = $this->createMock(ProcessedFile::class);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->willReturn(456);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::never())
            ->method('dispatch');

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener->onBeforeDeleted(new BeforeFileDeletedEvent($processedFileMock));
        $listener(new AfterFileDeletedEvent($processedFileMock));
    }

    /**
     * Tests that the remembered metadata record is consumed by the announcement
     * of the deletion, so a second event for the same file object, or a new
     * object that gets the identifier of the freed one, announces nothing.
     */
    #[Test]
    public function aRememberedRecordIsAnnouncedOnlyOnce(): void
    {
        $fileMock = $this->createFileMock();

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->willReturn(456);

        $dispatched = [];

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static function (object $event) use (&$dispatched): object {
                    $dispatched[] = $event;

                    return $event;
                }
            );

        $listener = new AfterFileDeletedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener->onBeforeDeleted(new BeforeFileDeletedEvent($fileMock));
        $listener(new AfterFileDeletedEvent($fileMock));
        $listener(new AfterFileDeletedEvent($fileMock));

        // The delete event and one deleted event
        self::assertCount(2, $dispatched);
        self::assertInstanceOf(DataHandlerRecordDeleteEvent::class, $dispatched[0]);
        self::assertInstanceOf(DataHandlerRecordDeletedEvent::class, $dispatched[1]);
    }
}
