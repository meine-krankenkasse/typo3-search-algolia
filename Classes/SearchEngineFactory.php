<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * This factory class is responsible for creating and managing instances of
 * search engines that communicate with external search services (like Algolia).
 * It implements the singleton pattern to ensure only one instance exists
 * per search engine type, improving performance and resource usage.
 *
 * The factory uses the SearchEngineRegistry to find the appropriate search engine
 * class for a given service subtype or domain model and creates instances as needed.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class SearchEngineFactory implements SingletonInterface
{
    /**
     * Cache of search engine instances.
     *
     * This property stores already created search engine instances indexed by their
     * service subtype to avoid creating multiple instances of the same search engine,
     * implementing a simple caching mechanism for better performance.
     *
     * @var array<string, SearchEngineInterface>
     */
    private array $instances = [];

    /**
     * Creates a search engine instance from the given class name.
     *
     * This private helper method uses TYPO3's GeneralUtility to instantiate
     * a search engine class. It handles the actual object creation process and
     * ensures that the created instance implements the SearchEngineInterface.
     *
     * @param class-string $className The fully qualified class name of the search engine to create
     *
     * @return SearchEngineInterface The created search engine instance
     */
    private function makeInstance(string $className): SearchEngineInterface
    {
        /** @var SearchEngineInterface $instance */
        $instance = GeneralUtility::makeInstance($className);

        return $instance;
    }

    /**
     * Creates a search engine instance from the given service subtype.
     *
     * This method is one of the main entry points for obtaining search engine instances.
     * It works by:
     * 1. Checking if an instance for the requested subtype already exists in the cache
     * 2. If not, searching the SearchEngineRegistry for a matching search engine configuration
     * 3. Creating a new instance if a matching configuration is found
     * 4. Storing the new instance in the cache for future use
     * 5. Returning null if no matching search engine is registered for the given subtype
     *
     * @param string $subtype The search engine service subtype identifier (e.g., 'algolia')
     *
     * @return SearchEngineInterface|null The search engine instance or null if no matching engine is registered
     */
    public function makeInstanceByServiceSubtype(string $subtype): ?SearchEngineInterface
    {
        if (isset($this->instances[$subtype])) {
            return $this->instances[$subtype];
        }

        foreach (SearchEngineRegistry::getRegisteredSearchEngines() as $service) {
            if ($service['subtype'] !== $subtype) {
                continue;
            }

            $searchEngineInstance = $this
                ->makeInstance($service['className']);

            $this->instances[$subtype] = $searchEngineInstance;

            return $this->instances[$subtype];
        }

        return null;
    }

    /**
     * Creates a search engine instance from the given search engine domain model.
     *
     * This method provides a convenient way to create search engine instances
     * directly from domain model objects. It extracts the engine identifier
     * from the domain model and delegates to makeInstanceByServiceSubtype()
     * to create the actual instance.
     *
     * This approach allows for a more object-oriented interface when working
     * with search engine configurations stored in the database.
     *
     * @param SearchEngine $searchEngine A search engine domain model instance containing configuration
     *
     * @return SearchEngineInterface|null The search engine instance or null if no matching engine is registered
     */
    public function makeInstanceBySearchEngineModel(SearchEngine $searchEngine): ?SearchEngineInterface
    {
        return $this->makeInstanceByServiceSubtype($searchEngine->getEngine());
    }
}
