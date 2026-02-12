<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine;

use Algolia\AlgoliaSearch\Api\SearchClient;

/**
 * Factory interface for creating Algolia SearchClient instances.
 *
 * This interface abstracts the creation of Algolia SearchClient objects,
 * allowing for dependency injection and easier testing of the AlgoliaSearchEngine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface SearchClientFactoryInterface
{
    /**
     * Creates a new Algolia SearchClient instance.
     *
     * @param string $appId  The Algolia application ID
     * @param string $apiKey The Algolia API key
     *
     * @return SearchClient The configured SearchClient instance
     */
    public function create(string $appId, string $apiKey): SearchClient;
}
