<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Backend\FieldWizard\IndexerTypeInfoText;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Hook\DataHandlerHook;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\NewsIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine\AlgoliaSearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Task\ExecuteSchedulableCommandTask;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandAdditionalFieldProvider;

defined('TYPO3') || exit('Access denied.');

call_user_func(static function (): void {
    // Add TypoScript automatically (to use it in backend modules)
    ExtensionManagementUtility::addTypoScript(
        Constants::EXTENSION_NAME,
        'setup',
        '@import "EXT:typo3_search_algolia/Configuration/TypoScript/setup.typoscript"'
    );

    ExtensionManagementUtility::addService(
        Constants::EXTENSION_NAME,
        'mkk_search_engine',
        AlgoliaSearchEngine::class,
        [
            'title'       => 'Algolia Search Service',
            'description' => 'Service which provides access to Algolia search engine',
            'subtype'     => 'algolia',
            'available'   => true,
            'priority'    => 50,
            'quality'     => 50,
            'os'          => '',
            'exec'        => '',
            'className'   => AlgoliaSearchEngine::class,
        ]
    );

    // Indexer registration
    IndexerRegistry::register(
        PageIndexer::class,
        PageIndexer::TABLE,
        'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:indexer.pages.title',
        'apps-pagetree-page-default'
    );

    IndexerRegistry::register(
        ContentIndexer::class,
        ContentIndexer::TABLE,
        'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:indexer.tt_content.title',
        'apps-pagetree-page-content-from-page',
    );

    IndexerRegistry::register(
        FileIndexer::class,
        FileIndexer::TABLE,
        'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:indexer.sys_file_metadata.title',
        'mimetypes-pdf',
    );

    if (ExtensionManagementUtility::isLoaded('news')) {
        IndexerRegistry::register(
            NewsIndexer::class,
            NewsIndexer::TABLE,
            'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:indexer.tx_news_domain_model_news.title',
            'content-news'
        );
    }

    // Add the task to index records from queue into search engine
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][ExecuteSchedulableCommandTask::class] = [
        'extension'        => 'typo3_search_algolia',
        'title'            => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:executeSchedulableCommandTask.name',
        'description'      => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:executeSchedulableCommandTask.description',
        'additionalFields' => ExecuteSchedulableCommandAdditionalFieldProvider::class,
    ];

    // Register DataHandler hooks
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = DataHandlerHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]  = DataHandlerHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['moveRecordClass'][]     = DataHandlerHook::class;

    // Custom render types
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1743533083] = [
        'nodeName' => 'IndexerTypeInfoText',
        'priority' => 50,
        'class'    => IndexerTypeInfoText::class,
    ];

    // Add our custom style sheet
    $GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets'][Constants::EXTENSION_NAME]
        = 'EXT:typo3_search_algolia/Resources/Public/Css/Module.css';
});
