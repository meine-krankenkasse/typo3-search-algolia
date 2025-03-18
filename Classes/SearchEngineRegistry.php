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
 * Class SearchEngineRegistry.
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
     * @return array<string, mixed>
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
