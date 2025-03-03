<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

defined('TYPO3') || exit('Access denied.');

call_user_func(static function (): void {
    // Add our custom style sheet
    $GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets']['typo3_search_algolia']
        = 'EXT:typo3_search_algolia/Resources/Public/Css/Module.css';
});
