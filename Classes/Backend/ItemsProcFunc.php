<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Backend;

use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineRegistry;

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
        foreach (SearchEngineRegistry::getRegisteredSearchEngines() as $service) {
            $config['items'][] = [
                'label' => $service['title'],
                'value' => $service['subtype'],
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
        foreach (IndexerRegistry::getRegisteredIndexers() as $indexer) {
            $config['items'][] = [
                'label' => $indexer['title'],
                'value' => $indexer['type'],
                'icon'  => $indexer['icon'],
            ];
        }
    }
}
