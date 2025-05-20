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

use function is_array;

/**
 * This class contains utility methods that are used in the TYPO3 backend to populate
 * dropdown menus and selection fields with dynamic content. It handles:
 *
 * - Populating search engine options in selection fields
 * - Populating indexer type options in selection fields
 * - Filtering and providing page type options
 * - Providing content element type options
 *
 * These methods are typically called by the TYPO3 TCA (Table Configuration Array)
 * system when rendering forms in the backend interface.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class TcaItemsProcessorFunctions
{
    /**
     * This method retrieves all registered search engines from the SearchEngineRegistry
     * and adds them as options to a selection field in the TYPO3 backend. Each search
     * engine is added with its title as the label and its subtype as the value.
     *
     * This is typically used in forms where administrators need to select which
     * search engine to use for a particular indexing configuration.
     *
     * @param array<string, mixed> $fieldDefinition The TCA field configuration array to be modified
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
     * This method retrieves all registered indexers from the IndexerRegistry and adds them as options to
     * a selection field in the TYPO3 backend. Each indexer is added with its title as the label, its
     * table name as the value, and its  associated icon for visual identification.
     *
     * This is typically used in forms where administrators need to select which
     * type of content should be indexed (pages, content elements, files, etc.).
     *
     * @param array<string, mixed> $fieldDefinition The TCA field configuration array to be modified
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
     * This method retrieves all available page types from the TYPO3 core and adds them as options to
     * a selection field in the TYPO3 backend. It filters out certain system page types that are not
     * suitable for indexing (recycler pages, backend user section pages, and spacer pages).
     *
     * The resulting list is sorted by page type value to provide a consistent
     * ordering in the selection field.
     *
     * This is typically used in forms where administrators need to select
     * which types of pages should be included in the search index.
     *
     * @param array<string, mixed> $fieldDefinition The TCA field configuration array to be modified
     */
    public function populateAvailablePageTypes(array &$fieldDefinition): void
    {
        $this->getTcaItemsProcessorFunctionsInstance()
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

    /**
     * This helper method creates and returns an instance of the TYPO3 core
     * TcaItemsProcessorFunctions class, which provides standard functionality
     * for populating TCA selection fields. This allows us to leverage the core
     * functionality while extending or customizing it for our specific needs.
     *
     * @return \TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions The TYPO3 core TCA items processor
     */
    private function getTcaItemsProcessorFunctionsInstance(): \TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions::class);
    }

    /**
     * This method retrieves all available content element types (CTypes) from the TYPO3 TCA configuration
     * and adds them as options to a selection field in the TYPO3 backend. It filters out divider items
     * and ensures that only valid content element types are included in the selection list.
     *
     * Each content element type is added with its label, value, and icon for
     * visual identification in the backend interface.
     *
     * This is typically used in forms where administrators need to select
     * which types of content elements should be included in the search index.
     *
     * @param array<string, mixed> $fieldDefinition The TCA field configuration array to be modified
     */
    public function populateAvailableContentTypes(array &$fieldDefinition): void
    {
        $contentTypes = $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? [];

        foreach ($contentTypes as $contentType) {
            // Skip non-arrays and divider items
            if (!is_array($contentType)) {
                continue;
            }

            if (!isset($contentType['value'])) {
                continue;
            }

            if ($contentType['value'] === '--div--') {
                continue;
            }

            $fieldDefinition['items'][] = [
                'label' => $contentType['label'],
                'value' => $contentType['value'],
                'icon'  => $contentType['icon'],
            ];
        }
    }
}
