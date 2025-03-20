<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Backend\ItemsProcFunc;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\NewsIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions;

return [
    'ctrl' => [
        'title'                    => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice',
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
                --div--;LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tca.tabs.general,
                    --palette--;;standard,
                --div--;LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tca.tabs.special,
                    --palette--;;special,
                --div--;LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tca.tabs.access,
                    --palette--;;visibility,
            ',
        ],
    ],
    'palettes' => [
        'standard' => [
            'label'    => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tca.palettes.standard',
            'showitem' => 'title, --linebreak--, description, --linebreak--, type, --linebreak--, search_engine',
        ],
        'special' => [
            'label'    => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tca.palettes.special',
            'showitem' => 'pages_doktype, --linebreak--, pages_single, --linebreak--, pages_recursive',
        ],
        'visibility' => [
            'label'    => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tca.palettes.visibility',
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
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.title',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.title.description',
            'config'      => [
                'type'     => 'input',
                'size'     => 40,
                'eval'     => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.description',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.description.description',
            'config'      => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
        'type' => [
            'exclude'     => false,
            'onChange'    => 'reload',
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.type',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.type.description',
            'config'      => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    [
                        'label' => '',
                        'value' => '',
                    ],
                ],
                'itemsProcFunc' => ItemsProcFunc::class . '->getIndexerTypes',
                'sortItems'     => [
                    'label' => 'asc',
                ],
                'minitems' => 1,
                'maxitems' => 1,
                'required' => true,
            ],
        ],
        'search_engine' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.search_engine',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.search_engine.description',
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectSingle',
                'foreign_table' => 'tx_typo3searchalgolia_domain_model_searchengine',
                'minitems'      => 1,
                'maxitems'      => 1,
            ],
        ],
        'pages_doktype' => [
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.pages_doktype',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.pages_doktype.description',
            'displayCond' => 'FIELD:type:IN:' . PageIndexer::TYPE,
            'config'      => [
                'type'          => 'select',
                'renderType'    => 'selectCheckBox',
                'itemsProcFunc' => TcaItemsProcessorFunctions::class . '->populateAvailablePageTypes',
                'size'          => 5,
                'autoSizeMax'   => 10,
                'minitems'      => 0,
                'maxitems'      => 100,
            ],
        ],
        'pages_single' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.pages_single',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.pages_single.description',
            'displayCond' => 'FIELD:type:IN:' . PageIndexer::TYPE . ',' . ContentIndexer::TYPE . ',' . NewsIndexer::TYPE,
            'config'      => [
                'type'        => 'group',
                'allowed'     => 'pages',
                'size'        => 5,
                'autoSizeMax' => 10,
                'minitems'    => 0,
                'maxitems'    => 100,
            ],
        ],
        'pages_recursive' => [
            'exclude'     => false,
            'label'       => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.pages_recursive',
            'description' => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.pages_recursive.description',
            'displayCond' => 'FIELD:type:IN:' . PageIndexer::TYPE . ',' . ContentIndexer::TYPE . ',' . NewsIndexer::TYPE,
            'config'      => [
                'type'        => 'group',
                'allowed'     => 'pages',
                'size'        => 5,
                'autoSizeMax' => 10,
                'minitems'    => 0,
                'maxitems'    => 100,
            ],
        ],
    ],
];
