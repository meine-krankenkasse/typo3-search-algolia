<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'tags' => [
        'backend.contextmenu',
    ],
    'imports' => [
        '@meine-krankenkasse/typo3-search-algolia/' => 'EXT:typo3_search_algolia/Resources/Public/JavaScript/',
    ],
];
