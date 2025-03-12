<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
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
        'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:indexer.page.title',
        PageIndexer::class,
        'apps-pagetree-page-default'
    );

    IndexerRegistry::register(
        'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:indexer.tt_content.title',
        ContentIndexer::class,
        'content-inside-text-img-right',
    );

    if (ExtensionManagementUtility::isLoaded('news')) {
        IndexerRegistry::register(
            'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:indexer.news.title',
            NewsIndexer::class,
            'content-news'
        );
    }

    // Add task
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][ExecuteSchedulableCommandTask::class] = [
        'extension'        => 'typo3_search_algolia',
        'title'            => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:executeSchedulableCommandTask.name',
        'description'      => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:executeSchedulableCommandTask.description',
        'additionalFields' => ExecuteSchedulableCommandAdditionalFieldProvider::class,
    ];

    // Add our custom style sheet
    $GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets'][Constants::EXTENSION_NAME]
        = 'EXT:typo3_search_algolia/Resources/Public/Css/Module.css';
});
