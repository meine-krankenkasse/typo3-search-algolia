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
 * Registry for managing indexer configurations.
 *
 * This class provides a central registry for all indexers in the system.
 * It allows for:
 * - Registering new indexers with their associated table names and metadata
 * - Retrieving the list of all registered indexers
 * - Looking up indexer-specific information like icons
 *
 * The registry stores indexer configurations in the TYPO3 global configuration
 * array, making them accessible throughout the system.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexerRegistry
{
    /**
     * Registers a new indexer in the system.
     *
     * This method adds a new indexer configuration to the global registry.
     * Each indexer is associated with a specific database table and includes
     * metadata like a title and icon for use in the TYPO3 backend interface.
     *
     * The registration process stores the indexer configuration in the TYPO3
     * global configuration array, making it available for the IndexerFactory
     * to create instances when needed.
     *
     * @param class-string $className The fully qualified class name of the indexer implementation
     * @param string       $tableName The database table name this indexer processes (must be unique among all indexers)
     * @param string       $title     The human-readable title of the indexer (used in backend interfaces)
     * @param string|null  $icon      The icon identifier for the indexer (used in backend interfaces)
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
     * This method retrieves all indexer configurations that have been registered
     * in the system. It accesses the TYPO3 global configuration array to fetch
     * the complete list of indexers with their associated metadata.
     *
     * If no indexers have been registered, an empty array is returned.
     *
     * @return array<int, array{className: class-string, tableName: string, title: string, icon: string|null}> Array of indexer configurations, each containing className, tableName, title, and icon
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
     * Returns the icon identifier for a specific indexer.
     *
     * This method looks up the icon associated with an indexer based on its
     * table name. It iterates through all registered indexers to find the
     * matching one and returns its icon identifier.
     *
     * If no matching indexer is found or if the indexer doesn't have an icon
     * configured, an empty string is returned.
     *
     * @param string $tableName The database table name of the indexer to look up
     *
     * @return string The icon identifier for the indexer or an empty string if not found
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
