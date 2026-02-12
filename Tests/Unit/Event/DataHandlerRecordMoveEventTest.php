<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DataHandlerRecordMoveEvent.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DataHandlerRecordMoveEvent::class)]
class DataHandlerRecordMoveEventTest extends TestCase
{
    /**
     * Tests that all constructor arguments are correctly stored and returned
     * by their respective getter methods. Verifies that getTable() returns
     * 'tt_content', getRecordUid() returns 10, and getTargetPid() returns 5,
     * matching the values passed during construction.
     */
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $event = new DataHandlerRecordMoveEvent('tt_content', 10, 5);

        self::assertSame('tt_content', $event->getTable());
        self::assertSame(10, $event->getRecordUid());
        self::assertSame(5, $event->getTargetPid());
    }

    /**
     * Tests that the previous page ID defaults to null when no value has been
     * explicitly set via setPreviousPid(). Verifies that getPreviousPid()
     * returns null immediately after constructing the event.
     */
    #[Test]
    public function previousPidIsNullByDefault(): void
    {
        $event = new DataHandlerRecordMoveEvent('pages', 1, 2);

        self::assertNull($event->getPreviousPid());
    }

    /**
     * Tests that setPreviousPid() correctly stores the provided integer value
     * and that getPreviousPid() subsequently returns that exact value. Sets
     * the previous page ID to 10 and verifies it is retrievable.
     */
    #[Test]
    public function setPreviousPidStoresValue(): void
    {
        $event = new DataHandlerRecordMoveEvent('pages', 1, 2);
        $event->setPreviousPid(10);

        self::assertSame(10, $event->getPreviousPid());
    }

    /**
     * Tests that setPreviousPid() accepts null to reset the previous page ID
     * after it has been set to an integer value. First sets the value to 10,
     * then resets it to null, and verifies getPreviousPid() returns null.
     */
    #[Test]
    public function setPreviousPidAcceptsNull(): void
    {
        $event = new DataHandlerRecordMoveEvent('pages', 1, 2);
        $event->setPreviousPid(10);
        $event->setPreviousPid(null);

        self::assertNull($event->getPreviousPid());
    }

    /**
     * Tests that setPreviousPid() returns the event instance itself, enabling
     * fluent method chaining. Verifies that the return value of setPreviousPid()
     * is the same object reference as the original event.
     */
    #[Test]
    public function setPreviousPidReturnsSelfForChaining(): void
    {
        $event  = new DataHandlerRecordMoveEvent('pages', 1, 2);
        $result = $event->setPreviousPid(5);

        self::assertSame($event, $result);
    }

    /**
     * Tests that the constructor accepts negative values for the target page ID,
     * which in TYPO3 indicates positioning relative to another record rather
     * than an absolute page ID. Verifies getTargetPid() returns -5 as provided.
     */
    #[Test]
    public function targetPidAcceptsNegativeValues(): void
    {
        $event = new DataHandlerRecordMoveEvent('tt_content', 1, -5);

        self::assertSame(-5, $event->getTargetPid());
    }
}
