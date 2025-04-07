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
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provides methods to dynamically populate table and field selection lists.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class TcaItemsProcessorFunctions
{
    /**
     * Populates the available search engines into the selection list.
     *
     * @param array<string, mixed> $fieldDefinition The configuration array
     */
    public function populateSearchEngines(array &$fieldDefinition): void
    {
        foreach (SearchEngineRegistry::getRegisteredSearchEngines() as $service) {
            $fieldDefinition['items'][] = [
                'label' => $service['title'],
                'value' => $service['subtype'],
            ];
        }
    }

    /**
     * Populates the available search engines into the selection list.
     *
     * @param array<string, mixed> $fieldDefinition The configuration array
     */
    public function populateIndexerTypes(array &$fieldDefinition): void
    {
        foreach (IndexerRegistry::getRegisteredIndexers() as $indexer) {
            $fieldDefinition['items'][] = [
                'label' => $indexer['title'],
                'value' => $indexer['tableName'],
                'icon'  => $indexer['icon'],
            ];
        }
    }

    /**
     * Populates the available page types into the selection list.
     *
     * @param array<string, mixed> $fieldDefinition The configuration array
     */
    public function populateAvailablePageTypes(array &$fieldDefinition): void
    {
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions::class)
            ->populateAvailablePageTypes($fieldDefinition);

        /** @var array{label: string, value: int|string, icon: string} $item */
        foreach ($fieldDefinition['items'] as $key => $item) {
            // Remove some page types
            switch ((int) $item['value']) {
                case PageRepository::DOKTYPE_RECYCLER:
                case PageRepository::DOKTYPE_BE_USER_SECTION:
                case PageRepository::DOKTYPE_SPACER:
                    unset($fieldDefinition['items'][$key]);
                    break;
            }
        }

        usort(
            $fieldDefinition['items'],
            static fn (array $a, array $b): int => ((int) $a['value']) <=> ((int) $b['value'])
        );
    }
}
