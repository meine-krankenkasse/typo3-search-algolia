<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DataHandlerRecordDeleteEvent.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DataHandlerRecordDeleteEvent::class)]
class DataHandlerRecordDeleteEventTest extends TestCase
{
    /**
     * Tests that the constructor correctly stores the table name and record UID,
     * and that getTable() returns 'pages' and getRecordUid() returns 42, matching
     * the values passed during construction.
     */
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $event = new DataHandlerRecordDeleteEvent('pages', 42);

        self::assertSame('pages', $event->getTable());
        self::assertSame(42, $event->getRecordUid());
    }

    /**
     * Tests that the constructor works with different table names beyond 'pages'.
     * Verifies that passing 'tt_content' as the table name and 99 as the record UID
     * results in getTable() and getRecordUid() returning those exact values.
     */
    #[Test]
    public function constructorAcceptsDifferentTableNames(): void
    {
        $event = new DataHandlerRecordDeleteEvent('tt_content', 99);

        self::assertSame('tt_content', $event->getTable());
        self::assertSame(99, $event->getRecordUid());
    }
}
