<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Backend;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_array;

/**
 * Provides methods to dynamically populate table and field selection lists.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ItemsProcFunc
{
    /**
     * Populates the available search engines into the selection list.
     *
     * @param array<string, mixed> $config The configuration array
     */
    public function getSearchEngines(array &$config): void
    {
        /** @var SearchEngineInterface $service */
        foreach ($GLOBALS['T3_SERVICES']['mkk_search_engine'] as $service) {
            $config['items'][] = [
                $service['title'],
                $service['subtype'],
            ];
        }
    }

    /**
     * Populates the available search engines into the selection list.
     *
     * @param array<string, mixed> $config The configuration array
     */
    public function getIndexerTypes(array &$config): void
    {
        if (isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_search_algolia']['indexer'])
            && is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_search_algolia']['indexer'])
        ) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_search_algolia']['indexer'] as $indexerConfiguration) {
                /** @var IndexerInterface $indexerInstance */
                $indexerInstance = GeneralUtility::makeInstance($indexerConfiguration['className']);

                $config['items'][] = [
                    $indexerConfiguration['title'],
                    $indexerInstance->getType(),
                    $indexerConfiguration['icon'],
                ];
            }
        }
    }
}
