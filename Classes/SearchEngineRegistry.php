<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia;

use function is_array;

/**
 * Registry for accessing search engine configurations.
 *
 * This class provides access to the search engine configurations registered
 * in the TYPO3 service system. Unlike the IndexerRegistry which actively
 * manages registrations, this class primarily serves as a reader for
 * configurations that are registered through the TYPO3 service system.
 *
 * The registry allows the SearchEngineFactory to discover available search
 * engine implementations and create appropriate instances based on their
 * configuration.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class SearchEngineRegistry
{
    /**
     * Returns the list of all registered search engines.
     *
     * This method retrieves all search engine configurations that have been
     * registered in the TYPO3 service system under the 'mkk_search_engine' key.
     * These configurations contain information about available search engine
     * implementations, including their class names and service subtypes.
     *
     * If no search engines have been registered, an empty array is returned.
     *
     * @return array<string, mixed> Array of search engine configurations indexed by service keys
     */
    public static function getRegisteredSearchEngines(): array
    {
        if (isset($GLOBALS['T3_SERVICES']['mkk_search_engine'])
            && is_array($GLOBALS['T3_SERVICES']['mkk_search_engine'])
        ) {
            return $GLOBALS['T3_SERVICES']['mkk_search_engine'];
        }

        return [];
    }
}
