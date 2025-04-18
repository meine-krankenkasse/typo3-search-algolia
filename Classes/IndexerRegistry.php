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
     * @param class-string $className The class name of the actual indexer
     * @param string       $tableName The name of the table to which the indexer refers (must be unique among all indexers)
     * @param string       $title     The title of the indexer (used inside TCA selectors)
     * @param string|null  $icon      The icon of the indexer (used inside TCA selectors)
     */
    public static function register(
        string $className,
        string $tableName,
        string $title,
        ?string $icon = null,
    ): void {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'][] = [
            'className' => $className,
            'tableName' => $tableName,
            'title'     => $title,
            'icon'      => $icon,
        ];
    }

    /**
     * Returns the list of all registered indexers.
     *
     * @return array<int, array{className: class-string, tableName: string, title: string, icon: string|null}>
     */
    public static function getRegisteredIndexers(): array
    {
        if (isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'])
            && is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'])
        ) {
            return $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'];
        }

        return [];
    }

    /**
     * Returns the icon of the indexer.
     *
     * @param string $tableName
     *
     * @return string
     */
    public static function getIndexerIcon(string $tableName): string
    {
        foreach (self::getRegisteredIndexers() as $indexerConfiguration) {
            if ($indexerConfiguration['tableName'] === $tableName) {
                return $indexerConfiguration['icon'] ?? '';
            }
        }

        return '';
    }
}
