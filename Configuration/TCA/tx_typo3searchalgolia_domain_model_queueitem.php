<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

defined('TYPO3') || exit('Access denied.');

return [
    'ctrl' => [
        'title'        => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_queueitem',
        'versioningWS' => false,
        'hideTable'    => true,
    ],
    'columns' => [
        'pid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'table_name' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'record_uid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'service_uid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'changed' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'priority' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
];
