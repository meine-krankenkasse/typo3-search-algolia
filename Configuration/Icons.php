<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'extension-mkk-module'               => [
        'provider' => SvgIconProvider::class,
        'source'   => 'EXT:typo3_search_algolia/Resources/Public/Icons/Module.svg',
    ],
    'extension-mkk-typo3-search-algolia' => [
        'provider' => SvgIconProvider::class,
        'source'   => 'EXT:typo3_search_algolia/Resources/Public/Icons/Extension.svg',
    ],
];
