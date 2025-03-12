<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\AbstractIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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

    /**
     * Returns an indexer instance.
     *
     * @param string $className
     * @param string $title
     * @param string $icon
     *
     * @return AbstractIndexer
     */
    private static function getInstance(
        string $className,
        string $title = '',
        string $icon = '',
    ): AbstractIndexer {
        /** @var AbstractIndexer $indexerInstance */
        $indexerInstance = GeneralUtility::makeInstance($className);
        $indexerInstance
            ->setTitle($title)
            ->setIcon($icon);

        return $indexerInstance;
    }

    /**
     * Returns all registered indexer and their configurations.
     *
     * @return AbstractIndexer[]
     */
    public static function getIndexers(): array
    {
        $indexerInstances = [];

        if (isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'])
            && is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'])
        ) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'] as $indexerConfiguration) {
                $indexerInstances[] = self::getInstance(
                    $indexerConfiguration['className'],
                    $indexerConfiguration['title'],
                    $indexerConfiguration['icon']
                );
            }
        }

        return $indexerInstances;
    }

    /**
     * Returns the indexer by a given indexer type.
     *
     * @param string $type
     *
     * @return IndexerInterface|null
     */
    public static function getIndexerByType(string $type): ?IndexerInterface
    {
        foreach (self::getIndexers() as $indexer) {
            if (!($indexer instanceof IndexerInterface)) {
                continue;
            }

            if ($indexer->getType() === $type) {
                return $indexer;
            }
        }

        return null;
    }
}
