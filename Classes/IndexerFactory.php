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
 * Class IndexerFactory.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexerFactory implements SingletonInterface
{
    /**
     * @var array<string, IndexerInterface>
     */
    private array $instances = [];

    /**
     * Creates an indexer instance from the given class name.
     *
     * @param class-string $className
     *
     * @return IndexerInterface
     */
    private function makeInstance(string $className): IndexerInterface
    {
        /** @var IndexerInterface $instance */
        $instance = GeneralUtility::makeInstance($className);

        return $instance;
    }

    /**
     * Creates and returns a new instance of an indexer for the given type. Returns NULL if the
     * specified type is not registered and therefore no instance could be created.
     *
     * @param string $type The indexer type
     *
     * @return IndexerInterface|null
     */
    public function makeInstanceByType(string $type): ?IndexerInterface
    {
        if (isset($this->instances[$type])) {
            return $this->instances[$type];
        }

        foreach (IndexerRegistry::getRegisteredIndexers() as $indexerConfiguration) {
            if ($indexerConfiguration['type'] !== $type) {
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
