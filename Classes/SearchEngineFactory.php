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
 * Class SearchEngineFactory.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class SearchEngineFactory implements SingletonInterface
{
    /**
     * @var array<string, SearchEngineInterface>
     */
    private array $instances = [];

    /**
     * Creates a search engine instance from the given class name.
     *
     * @param class-string $className
     *
     * @return SearchEngineInterface
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
     * @param string $subtype
     *
     * @return SearchEngineInterface|null
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
     * @param SearchEngine $searchEngine A search engine domain model instance
     *
     * @return SearchEngineInterface|null
     */
    public function makeInstanceBySearchEngineModel(SearchEngine $searchEngine): ?SearchEngineInterface
    {
        return $this->makeInstanceByServiceSubtype($searchEngine->getEngine());
    }
}
