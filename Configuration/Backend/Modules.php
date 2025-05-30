<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Controller\AdministrationModuleController;
use MeineKrankenkasse\Typo3SearchAlgolia\Controller\QueueModuleController;

// Caution, variable name must not exist within \TYPO3\CMS\Core\Package\AbstractServiceProvider::configureBackendModules
return [
    // MKK main module
    'mkk_module' => [
        'labels'         => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'extension-mkk-module',
        'position'       => [
            'after' => 'web',
        ],
    ],
    // MKK search main module
    'mkk_typo3_search' => [
        'parent'                                   => 'mkk_module',
        'position'                                 => [],
        'access'                                   => 'admin',
        'iconIdentifier'                           => 'extension-mkk-typo3-search',
        'path'                                     => '/module/meine-krankenkasse/typo3-search',
        'labels'                                   => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang_mod_search.xlf',
        'extensionName'                            => 'Typo3SearchAlgolia',
        'inheritNavigationComponentFromMainModule' => false,
        'navigationComponent'                      => '@typo3/backend/page-tree/page-tree-element',
    ],
    // MKK search sub modules
    'mkk_typo3_search_administration' => [
        'parent'         => 'mkk_typo3_search',
        'access'         => 'admin',
        'path'           => '/module/meine-krankenkasse/typo3-search/administration',
        'iconIdentifier' => 'extension-mkk-typo3-search',
        'labels'         => [
            'title' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang_mod_search.xlf:mod_administration',
        ],
        'extensionName'     => 'Typo3SearchAlgolia',
        'controllerActions' => [
            AdministrationModuleController::class => [
                'index',
                'clearIndex',
            ],
        ],
    ],
    'mkk_typo3_search_queue' => [
        'parent'         => 'mkk_typo3_search',
        'access'         => 'admin',
        'path'           => '/module/meine-krankenkasse/typo3-search/queue',
        'iconIdentifier' => 'extension-mkk-typo3-search',
        'labels'         => [
            'title' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang_mod_search.xlf:mod_queue',
        ],
        'extensionName'     => 'Typo3SearchAlgolia',
        'controllerActions' => [
            QueueModuleController::class => [
                'index',
            ],
        ],
    ],
];
