<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Utility;

use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Decides whether a record is a draft version of a workspace.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class WorkspaceVersion
{
    /**
     * Private constructor, the class only offers static methods.
     */
    private function __construct()
    {
    }

    /**
     * Tells whether a record is a placeholder, a move pointer or a version of a live record.
     *
     * The database returns the version columns as integers or as strings, so they are
     * converted before they are compared with the version state.
     *
     * @param array<string, int|string|null> $record The record, with the fields t3ver_state and t3ver_oid
     *
     * @return bool TRUE if the record is a draft of a workspace
     */
    public static function isDraft(array $record): bool
    {
        if (
            isset($record['t3ver_state'])
            && (VersionState::tryFrom((int) $record['t3ver_state']) !== VersionState::DEFAULT_STATE)
        ) {
            return true;
        }

        return isset($record['t3ver_oid']) && (((int) $record['t3ver_oid']) !== 0);
    }
}
