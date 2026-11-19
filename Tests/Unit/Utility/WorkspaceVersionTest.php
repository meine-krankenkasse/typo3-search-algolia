<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Utility;

use MeineKrankenkasse\Typo3SearchAlgolia\Utility\WorkspaceVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkspaceVersion.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(WorkspaceVersion::class)]
final class WorkspaceVersionTest extends TestCase
{
    /**
     * Tests that a live record is no draft and a version of a workspace is one, whether the
     * database returns the columns as integers or as strings.
     *
     * @param array<string, int|string|null> $record   The version columns of the record
     * @param bool                           $expected Whether the record is a draft
     */
    #[Test]
    #[DataProvider('records')]
    public function isDraftDecidesByTheStateAndTheLiveRecordOfTheVersion(array $record, bool $expected): void
    {
        self::assertSame($expected, WorkspaceVersion::isDraft($record));
    }

    /**
     * Provides the version columns of live records and of versions.
     *
     * @return iterable<string, array{array<string, int|string|null>, bool}>
     */
    public static function records(): iterable
    {
        yield 'live record with integer columns' => [['t3ver_state' => 0, 't3ver_oid' => 0], false];
        yield 'live record with string columns' => [['t3ver_state' => '0', 't3ver_oid' => '0'], false];
        yield 'record without version columns' => [[], false];
        yield 'new placeholder' => [['t3ver_state' => 1, 't3ver_oid' => 0], true];
        yield 'delete placeholder' => [['t3ver_state' => '2', 't3ver_oid' => '0'], true];
        yield 'move pointer' => [['t3ver_state' => 4, 't3ver_oid' => 0], true];
        yield 'version of a live record' => [['t3ver_state' => 0, 't3ver_oid' => 20], true];
        yield 'version of a live record with string columns' => [['t3ver_state' => '0', 't3ver_oid' => '20'], true];
    }
}
