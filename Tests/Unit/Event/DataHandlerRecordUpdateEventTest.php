<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DataHandlerRecordUpdateEvent.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DataHandlerRecordUpdateEvent::class)]
class DataHandlerRecordUpdateEventTest extends TestCase
{
    /**
     * Tests that all constructor arguments are correctly stored and returned
     * by their respective getter methods. Verifies that getTable() returns 'pages',
     * getRecordUid() returns 42, and getFields() returns the exact associative
     * array of field values passed during construction.
     */
    #[Test]
    public function gettersReturnConstructorValues(): void
    {
        $fields = ['title' => 'Updated', 'hidden' => 0];
        $event  = new DataHandlerRecordUpdateEvent('pages', 42, $fields);

        self::assertSame('pages', $event->getTable());
        self::assertSame(42, $event->getRecordUid());
        self::assertSame($fields, $event->getFields());
    }

    /**
     * Tests that the fields parameter defaults to an empty array when omitted
     * from the constructor call. Verifies that getFields() returns an empty
     * array when only the table name and record UID are provided.
     */
    #[Test]
    public function fieldsDefaultsToEmptyArray(): void
    {
        $event = new DataHandlerRecordUpdateEvent('pages', 1);

        self::assertSame([], $event->getFields());
    }

    /**
     * Tests that the constructor explicitly accepts an empty array for the fields
     * parameter without error. Verifies that getFields() returns an empty array
     * when an empty array is explicitly passed as the third constructor argument.
     */
    #[Test]
    public function constructorAcceptsEmptyFields(): void
    {
        $event = new DataHandlerRecordUpdateEvent('tt_content', 5, []);

        self::assertSame([], $event->getFields());
    }
}
