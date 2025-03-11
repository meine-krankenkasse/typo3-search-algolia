<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit('Access denied.');

// TCA override for sys_template table
call_user_func(static function (): void {
    ExtensionManagementUtility::addStaticFile(
        Constants::EXTENSION_NAME,
        'Configuration/TypoScript',
        'MKK: TYPO3 Search Algolia'
    );
});
