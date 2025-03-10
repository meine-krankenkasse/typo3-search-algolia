<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Class SolrSearchEngine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class SolrSearchEngine implements SearchEngineInterface
{
    /**
     * Constructor.
     *
     * @param ExtensionConfiguration $extensionConfiguration
     */
    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /**
     * Initializes the service.
     */
    public function init(): bool
    {
        return false;
    }

    public function indexOpen(string $indexName): void
    {
    }

    public function indexClose(): void
    {
    }

    public function indexExists(string $indexName): bool
    {
        return false;
    }

    public function indexDelete(string $indexName): bool
    {
        return false;
    }

    public function indexCommit(): bool
    {
        return false;
    }

    public function indexMove(string $indexName, string $destination): bool
    {
        return false;
    }

    public function documentAdd(Document $document): bool
    {
        return false;
    }

    public function documentUpdate(Document $document): bool
    {
        return false;
    }

    public function documentDelete(string $objectId): bool
    {
        return false;
    }
}
