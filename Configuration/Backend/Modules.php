<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Controller\SearchModuleController;

// Caution, variable name must not exist within \TYPO3\CMS\Core\Package\AbstractServiceProvider::configureBackendModules
return [
    'mkk_module' => [
        'labels'         => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'extension-mkk-module',
        'position'       => [
            'after' => 'web',
        ],
    ],
    'mkk_typo3_search_algolia' => [
        'parent'                                   => 'mkk_module',
        'position'                                 => [],
        'access'                                   => 'user',
        'iconIdentifier'                           => 'extension-mkk-typo3-search-algolia',
        'path'                                     => '/module/meine-krankenkasse/typo3-search-algolia',
        'labels'                                   => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang_mod_algolia.xlf',
        'extensionName'                            => 'Typo3SearchAlgolia',
        'inheritNavigationComponentFromMainModule' => false,
        'navigationComponent'                      => '@typo3/backend/page-tree/page-tree-element',
        'controllerActions'                        => [
            SearchModuleController::class => [
                'index',
            ],
        ],
    ],
];
