<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Domain\Model;

use DateTime;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for QueueItem.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(QueueItem::class)]
class QueueItemTest extends UnitTestCase
{
    /**
     * Tests that a newly constructed QueueItem has its table name property
     * initialized to an empty string. Verifies that getTableName() returns
     * an empty string when no value has been explicitly set.
     */
    #[Test]
    public function tableNameDefaultsToEmptyString(): void
    {
        $item = new QueueItem();

        self::assertSame('', $item->getTableName());
    }

    /**
     * Tests that setTableName() correctly stores the provided string value.
     * Verifies that getTableName() returns the exact table name string
     * that was passed to the setter.
     */
    #[Test]
    public function setTableNameStoresValue(): void
    {
        $item = (new QueueItem())->setTableName('pages');

        self::assertSame('pages', $item->getTableName());
    }

    /**
     * Tests that setTableName() returns the QueueItem instance itself to
     * support a fluent interface. Verifies that the return value is the
     * same object reference as the original item.
     */
    #[Test]
    public function setTableNameReturnsSelfForChaining(): void
    {
        $item   = new QueueItem();
        $result = $item->setTableName('pages');

        self::assertSame($item, $result);
    }

    /**
     * Tests that a newly constructed QueueItem has its record UID property
     * initialized to zero. Verifies that getRecordUid() returns 0 when
     * no value has been explicitly set.
     */
    #[Test]
    public function recordUidDefaultsToZero(): void
    {
        $item = new QueueItem();

        self::assertSame(0, $item->getRecordUid());
    }

    /**
     * Tests that setRecordUid() correctly stores the provided integer value.
     * Verifies that getRecordUid() returns the exact UID that was passed
     * to the setter.
     */
    #[Test]
    public function setRecordUidStoresValue(): void
    {
        $item = (new QueueItem())->setRecordUid(42);

        self::assertSame(42, $item->getRecordUid());
    }

    /**
     * Tests that a newly constructed QueueItem has its service UID property
     * initialized to zero. Verifies that getServiceUid() returns 0 when
     * no value has been explicitly set.
     */
    #[Test]
    public function serviceUidDefaultsToZero(): void
    {
        $item = new QueueItem();

        self::assertSame(0, $item->getServiceUid());
    }

    /**
     * Tests that setServiceUid() correctly stores the provided integer value.
     * Verifies that getServiceUid() returns the exact UID that was passed
     * to the setter.
     */
    #[Test]
    public function setServiceUidStoresValue(): void
    {
        $item = (new QueueItem())->setServiceUid(5);

        self::assertSame(5, $item->getServiceUid());
    }

    /**
     * Tests that setChanged() correctly stores the provided DateTime instance.
     * Verifies that getChanged() returns the exact same DateTime object
     * reference that was passed to the setter.
     */
    #[Test]
    public function setChangedStoresDateTime(): void
    {
        $dateTime = new DateTime('2024-01-15 10:30:00');
        $item     = (new QueueItem())->setChanged($dateTime);

        self::assertSame($dateTime, $item->getChanged());
    }

    /**
     * Tests that a newly constructed QueueItem has its priority property
     * initialized to zero. Verifies that getPriority() returns 0 when
     * no value has been explicitly set.
     */
    #[Test]
    public function priorityDefaultsToZero(): void
    {
        $item = new QueueItem();

        self::assertSame(0, $item->getPriority());
    }

    /**
     * Tests that setPriority() correctly stores the provided integer value.
     * Verifies that getPriority() returns the exact priority value that
     * was passed to the setter.
     */
    #[Test]
    public function setPriorityStoresValue(): void
    {
        $item = (new QueueItem())->setPriority(10);

        self::assertSame(10, $item->getPriority());
    }

    /**
     * Tests that all setter methods on QueueItem can be chained together in
     * a fluent interface style. Verifies that after chaining setTableName(),
     * setRecordUid(), setServiceUid(), setChanged(), and setPriority(), each
     * corresponding getter returns the correct value.
     */
    #[Test]
    public function fluentInterfaceWorksForAllSetters(): void
    {
        $dateTime = new DateTime();

        $item = (new QueueItem())
            ->setTableName('tt_content')
            ->setRecordUid(100)
            ->setServiceUid(3)
            ->setChanged($dateTime)
            ->setPriority(5);

        self::assertSame('tt_content', $item->getTableName());
        self::assertSame(100, $item->getRecordUid());
        self::assertSame(3, $item->getServiceUid());
        self::assertSame($dateTime, $item->getChanged());
        self::assertSame(5, $item->getPriority());
    }
}
