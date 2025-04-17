<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit('Access denied.');

ExtensionManagementUtility::addTCAcolumns(
    'sys_file_metadata',
    [
        'no_search' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:sys_file_metadata.no_search',
            'config'  => [
                'type'       => 'check',
                'renderType' => 'checkboxToggle',
                'items'      => [
                    [
                        'label'              => '',
                        'invertStateDisplay' => true,
                    ],
                ],
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
    ]
);

ExtensionManagementUtility::addToAllTCAtypes(
    'sys_file_metadata',
    '--div--;LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:sys_file_metadata.tabs.behaviour,no_search'
);
