<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;

/**
 * Class IndexerRegistry.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexerRegistry
{
    /**
     * Registers a new indexer.
     *
     * @param string      $title     The title of the indexer (used inside TCA selectors)
     * @param string      $className The class name of the actual indexer
     * @param string|null $icon      The icon of the indexer (used inside TCA selectors)
     */
    public static function register(
        string $title,
        string $className,
        ?string $icon = null,
    ): void {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'][] = [
            'title'     => $title,
            'className' => $className,
            'icon'      => $icon,
        ];
    }
}
