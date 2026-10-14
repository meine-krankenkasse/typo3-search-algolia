<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Utility;

use MeineKrankenkasse\Typo3SearchAlgolia\Utility\PublicationWindow;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unit tests for PublicationWindow.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(PublicationWindow::class)]
final class PublicationWindowTest extends TestCase
{
    /**
     * Frees the context singleton that the current time is read from.
     *
     * @return void
     */
    #[Override]
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /**
     * Tests that a record is outside of its window when it has not started or has ended,
     * and that the border of the window is decided the way the frontend decides it.
     *
     * @param array<string, int> $record   The record with its start and end time
     * @param bool               $expected Whether the record is outside of its window
     */
    #[Test]
    #[DataProvider('windows')]
    public function isOutsideDecidesByTheStartAndEndTime(array $record, bool $expected): void
    {
        self::assertSame($expected, PublicationWindow::isOutside($record, 1000));
    }

    /**
     * Tests that a hidden record counts like one outside of its window.
     */
    #[Test]
    public function isHiddenOrOutsideAlsoCountsAHiddenRecord(): void
    {
        self::assertTrue(PublicationWindow::isHiddenOrOutside(['hidden' => 1], 1000));
        self::assertTrue(PublicationWindow::isHiddenOrOutside(['hidden' => 0, 'endtime' => 999], 1000));
        self::assertFalse(PublicationWindow::isHiddenOrOutside(['hidden' => 0, 'starttime' => 1000], 1000));
    }

    /**
     * Tests that the current time of the request context is used when no time is given.
     */
    #[Test]
    public function isOutsideReadsTheCurrentTimeWhenNoneIsGiven(): void
    {
        $now = PublicationWindow::now();

        self::assertGreaterThan(0, $now);
        self::assertTrue(PublicationWindow::isOutside(['starttime' => $now + 3600]));
        self::assertFalse(PublicationWindow::isOutside(['starttime' => $now - 3600, 'endtime' => $now + 3600]));
    }

    /**
     * Provides records with their publication window.
     *
     * @return iterable<string, array{array<string, int>, bool}>
     */
    public static function windows(): iterable
    {
        yield 'no window' => [[], false];
        yield 'zero times' => [['starttime' => 0, 'endtime' => 0], false];
        yield 'inside' => [['starttime' => 500, 'endtime' => 2000], false];
        yield 'starts now' => [['starttime' => 1000, 'endtime' => 0], false];
        yield 'not started' => [['starttime' => 1001, 'endtime' => 0], true];
        yield 'ends now' => [['starttime' => 0, 'endtime' => 1000], true];
        yield 'ended' => [['starttime' => 0, 'endtime' => 999], true];
        yield 'ends later' => [['starttime' => 0, 'endtime' => 1001], false];
    }
}
