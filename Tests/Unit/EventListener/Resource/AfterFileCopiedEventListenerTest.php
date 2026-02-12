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
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileCopiedEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileCopiedEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * Unit tests for AfterFileCopiedEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AfterFileCopiedEventListener::class)]
class AfterFileCopiedEventListenerTest extends TestCase
{
    /**
     * Tests that invoking the listener dispatches a DataHandlerRecordUpdateEvent when
     * the new file has a valid metadata UID.
     */
    #[Test]
    public function invokeDispatchesUpdateEventWhenNewFileHasMetadataUid(): void
    {
        $originalFileMock = $this->createMock(File::class);
        $newFileMock      = $this->createMock(FileInterface::class);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->with($newFileMock)
            ->willReturn(456);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (DataHandlerRecordUpdateEvent $event): bool => $event->getTable() === 'sys_file_metadata'
                    && $event->getRecordUid() === 456
            ));

        $folderMock      = $this->createMock(Folder::class);
        $fileCopiedEvent = new AfterFileCopiedEvent($originalFileMock, $folderMock, 'newfile.pdf', $newFileMock);

        $listener = new AfterFileCopiedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileCopiedEvent);
    }

    /**
     * Tests that invoking the listener does not dispatch any event when the new
     * file has no valid metadata UID.
     */
    #[Test]
    public function invokeDoesNotDispatchEventWhenNewFileHasNoMetadata(): void
    {
        $originalFileMock = $this->createMock(File::class);
        $newFileMock      = $this->createMock(FileInterface::class);

        /** @var MockObject&FileHandler $fileHandlerMock */
        $fileHandlerMock = $this->createMock(FileHandler::class);
        $fileHandlerMock
            ->method('getMetadataUid')
            ->with($newFileMock)
            ->willReturn(false);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::never())
            ->method('dispatch');

        $folderMock      = $this->createMock(Folder::class);
        $fileCopiedEvent = new AfterFileCopiedEvent($originalFileMock, $folderMock, 'newfile.pdf', $newFileMock);

        $listener = new AfterFileCopiedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileCopiedEvent);
    }

    /**
     * Tests that invoking the listener does not dispatch any event when the new
     * file is not a FileInterface instance (null).
     */
    #[Test]
    public function invokeDoesNotDispatchEventWhenNewFileIsNotFileInterface(): void
    {
        $originalFileMock = $this->createMock(File::class);

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

        $folderMock      = $this->createMock(Folder::class);
        $fileCopiedEvent = new AfterFileCopiedEvent($originalFileMock, $folderMock, 'newfile.pdf', null);

        $listener = new AfterFileCopiedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileCopiedEvent);
    }
}
