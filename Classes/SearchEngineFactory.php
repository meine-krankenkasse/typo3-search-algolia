<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia;

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
    private function create(string $className): SearchEngineInterface
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
    public function createBySubtype(string $subtype): ?SearchEngineInterface
    {
        if (isset($this->instances[$subtype])) {
            return $this->instances[$subtype];
        }

        foreach (SearchEngineRegistry::getRegisteredSearchEngines() as $service) {
            if ($service['subtype'] !== $subtype) {
                continue;
            }

            $indexerInstance = $this
                ->create($service['className']);

            $this->instances[$subtype] = $indexerInstance;

            return $this->instances[$subtype];
        }

        return null;
    }
}
