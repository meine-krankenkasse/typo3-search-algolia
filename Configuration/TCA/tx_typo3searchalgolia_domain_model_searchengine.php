<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Backend\TcaItemsProcessorFunctions;

defined('TYPO3') || exit('Access denied.');

return [
    'ctrl' => [
        'title'                    => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine',
        'label'                    => 'title',
        'tstamp'                   => 'tstamp',
        'crdate'                   => 'crdate',
        'delete'                   => 'deleted',
        'versioningWS'             => false,
        'iconfile'                 => 'EXT:typo3_search_algolia/Resources/Public/Icons/Extension.svg',
        'hideTable'                => false,
        'languageField'            => 'sys_language_uid',
        'transOrigPointerField'    => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource'        => 'l10n_source',
        'default_sortby'           => 'title ASC',
        'searchFields'             => 'title, description',
        'enablecolumns'            => [
            'disabled' => 'hidden',
        ],
    ],
    'interface' => [
        'maxSingleDBListItems' => 50,
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    --palette--;;standard,
                --div--;LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.tabs.access,
                    --palette--;;visibility,
            ',
        ],
    ],
    'palettes' => [
        'standard' => [
            'label'    => 'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:CType.div.standard',
            'showitem' => 'title, --linebreak--, description, --linebreak--, engine, --linebreak--, index_name',
        ],
        'visibility' => [
            'label'    => 'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.palettes.visibility',
            'showitem' => 'hidden',
        ],
    ],
    'columns' => [
        'sys_language_uid' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config'  => ['type' => 'language'],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'label'       => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config'      => [
                'type'     => 'group',
                'allowed'  => 'tx_nrcconstructionsiteservice_domain_model_constructionsite',
                'size'     => 1,
                'maxitems' => 1,
                'minitems' => 0,
                'default'  => 0,
            ],
        ],
        'l10n_source' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'l10n_diffsource' => [
            'config' => [
                'type'    => 'passthrough',
                'default' => '',
            ],
        ],
        'pid' => [
            'label'  => 'pid',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'crdate' => [
            'label'  => 'crdate',
            'config' => [
                'type' => 'datetime',
            ],
        ],
        'tstamp' => [
            'label'  => 'tstamp',
            'config' => [
                'type' => 'datetime',
            ],
        ],
        'starttime' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.starttime',
            'config'  => [
                'type'      => 'datetime',
                'default'   => 0,
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'endtime' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config'  => [
                'type'    => 'datetime',
                'default' => 0,
                'range'   => [
                    'upper' => mktime(
                        0,
                        0,
                        0,
                        1,
                        1,
                        2038
                    ),
                ],
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'hidden' => [
            'l10n_mode' => 'exclude',
            'exclude'   => true,
            'label'     => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config'    => [
                'type'       => 'check',
                'renderType' => 'checkboxToggle',
                'default'    => 0,
            ],
        ],
        'title' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.title',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.title.description',
            'config'      => [
                'type'     => 'input',
                'size'     => 40,
                'eval'     => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.description',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.description.description',
            'config'      => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'engine' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.engine',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.engine.description',
            'config'      => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    [
                        'label' => '',
                        'value' => '',
                    ],
                ],
                'itemsProcFunc' => TcaItemsProcessorFunctions::class . '->populateSearchEngines',
                'minitems'      => 1,
                'maxitems'      => 1,
                'required'      => true,
            ],
        ],
        'index_name' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.index_name',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_searchengine.index_name.description',
            'config'      => [
                'type'     => 'input',
                'size'     => 40,
                'eval'     => 'trim',
                'required' => true,
            ],
        ],
    ],
];
