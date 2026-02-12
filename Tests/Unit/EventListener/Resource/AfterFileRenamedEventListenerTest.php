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
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AbstractAfterFileEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileRenamedEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\Event\AfterFileRenamedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Unit tests for AfterFileRenamedEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AbstractAfterFileEventListener::class)]
#[CoversClass(AfterFileRenamedEventListener::class)]
#[UsesClass(DataHandlerRecordUpdateEvent::class)]
class AfterFileRenamedEventListenerTest extends TestCase
{
    /**
     * Tests that invoking the listener dispatches a DataHandlerRecordUpdateEvent when
     * the file handler returns a valid metadata UID.
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
            ->willReturn(789);

        /** @var MockObject&EventDispatcherInterface $eventDispatcherMock */
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcherMock
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (DataHandlerRecordUpdateEvent $event): bool => $event->getTable() === 'sys_file_metadata'
                    && $event->getRecordUid() === 789
            ));

        $fileRenamedEvent = new AfterFileRenamedEvent($fileMock, 'old_name.pdf');

        $listener = new AfterFileRenamedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileRenamedEvent);
    }

    /**
     * Tests that invoking the listener does not dispatch any event when the file
     * handler returns false for the metadata UID.
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

        $fileRenamedEvent = new AfterFileRenamedEvent($fileMock, 'old_name.pdf');

        $listener = new AfterFileRenamedEventListener($eventDispatcherMock, $fileHandlerMock);
        $listener($fileRenamedEvent);
    }
}
