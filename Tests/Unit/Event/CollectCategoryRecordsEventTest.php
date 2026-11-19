<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CollectCategoryRecordsEvent.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(CollectCategoryRecordsEvent::class)]
final class CollectCategoryRecordsEventTest extends TestCase
{
    /**
     * Added records are merged into the table's list without duplicates, and a
     * table not collected so far gets its own entry.
     */
    #[Test]
    public function addRecordUidsMergesWithoutDuplicates(): void
    {
        $event = new CollectCategoryRecordsEvent(
            39,
            [
                'pages' => [2, 5],
            ],
        );

        $result = $event
            ->addRecordUids('pages', [5, 7])
            ->addRecordUids('sys_file_metadata', [10, 10]);

        self::assertSame($event, $result);
        self::assertSame(
            [
                'pages'             => [2, 5, 7],
                'sys_file_metadata' => [10],
            ],
            $event->getRecordUids(),
        );
    }

    /**
     * Adding an empty list does not create an empty table entry.
     */
    #[Test]
    public function addRecordUidsIgnoresAnEmptyList(): void
    {
        $event = new CollectCategoryRecordsEvent(39, []);

        $event->addRecordUids('pages', []);

        self::assertSame([], $event->getRecordUids());
    }
}
