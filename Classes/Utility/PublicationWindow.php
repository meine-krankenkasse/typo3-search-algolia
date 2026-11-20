<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Utility;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Decides whether a record is outside of its publication window at a given time.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class PublicationWindow
{
    /**
     * Private constructor, the class only offers static methods.
     */
    private function __construct()
    {
    }

    /**
     * Returns the current time of the request context.
     *
     * @return int The current time as a UNIX timestamp
     */
    public static function now(): int
    {
        return (int) GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
    }

    /**
     * Tells whether a record is hidden, or has not started or has ended at the given time.
     *
     * @param array<string, int|string|null> $record The record, with the fields hidden, starttime and endtime
     * @param int|null                       $now    The time to decide for, the current time if omitted
     *
     * @return bool TRUE if the record is hidden or outside of its publication window
     */
    public static function isHiddenOrOutside(
        array $record,
        ?int $now = null,
    ): bool {
        return (((int) ($record['hidden'] ?? 0)) === 1)
            || self::isOutside($record, $now);
    }

    /**
     * Tells whether a record has not started or has ended at the given time.
     *
     * @param array<string, int|string|null> $record The record, with the fields starttime and endtime
     * @param int|null                       $now    The time to decide for, the current time if omitted
     *
     * @return bool TRUE if the record is outside of its publication window
     */
    public static function isOutside(
        array $record,
        ?int $now = null,
    ): bool {
        $now ??= self::now();

        $starttime = (int) ($record['starttime'] ?? 0);
        $endtime   = (int) ($record['endtime'] ?? 0);

        return ($starttime > $now) || (($endtime > 0) && ($endtime <= $now));
    }
}
