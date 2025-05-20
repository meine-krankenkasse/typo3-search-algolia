<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Factory for creating indexer instances.
 *
 * This factory class is responsible for creating and managing instances of
 * indexers that process different types of content for search indexing.
 * It implements the singleton pattern to ensure only one instance exists
 * per indexer type, improving performance and resource usage.
 *
 * The factory uses the IndexerRegistry to find the appropriate indexer class
 * for a given content type and creates instances as needed.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexerFactory implements SingletonInterface
{
    /**
     * Cache of indexer instances.
     *
     * This property stores already created indexer instances indexed by their type
     * to avoid creating multiple instances of the same indexer, implementing
     * a simple caching mechanism for better performance.
     *
     * @var array<string, IndexerInterface>
     */
    private array $instances = [];

    /**
     * Creates an indexer instance from the given class name.
     *
     * This private helper method uses TYPO3's GeneralUtility to instantiate
     * an indexer class. It handles the actual object creation process and
     * ensures that the created instance implements the IndexerInterface.
     *
     * @param class-string $className The fully qualified class name of the indexer to create
     *
     * @return IndexerInterface The created indexer instance
     */
    private function makeInstance(string $className): IndexerInterface
    {
        /** @var IndexerInterface $instance */
        $instance = GeneralUtility::makeInstance($className);

        return $instance;
    }

    /**
     * Creates and returns an indexer instance for the given content type.
     *
     * This method is the main entry point for obtaining indexer instances. It:
     * 1. Checks if an instance for the requested type already exists in the cache
     * 2. If not, searches the IndexerRegistry for a matching indexer configuration
     * 3. Creates a new instance if a matching configuration is found
     * 4. Stores the new instance in the cache for future use
     * 5. Returns null if no matching indexer is registered for the given type
     *
     * @param string $type The indexer type/table name (e.g., 'pages', 'tt_content', 'tx_news_domain_model_news')
     *
     * @return IndexerInterface|null The indexer instance or null if no matching indexer is registered
     */
    public function makeInstanceByType(string $type): ?IndexerInterface
    {
        if (isset($this->instances[$type])) {
            return $this->instances[$type];
        }

        foreach (IndexerRegistry::getRegisteredIndexers() as $indexerConfiguration) {
            if ($indexerConfiguration['tableName'] !== $type) {
                continue;
            }

            $indexerInstance = $this
                ->makeInstance($indexerConfiguration['className']);

            $this->instances[$type] = $indexerInstance;

            return $this->instances[$type];
        }

        return null;
    }
}
