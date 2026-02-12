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
use Override;

/**
 * Default factory for creating Algolia SearchClient instances.
 *
 * This factory uses the Algolia SDK's static create method to instantiate
 * SearchClient objects with the provided credentials.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class AlgoliaSearchClientFactory implements SearchClientFactoryInterface
{
    /**
     * Creates a new Algolia SearchClient instance using the Algolia SDK.
     *
     * @param string $appId  The Algolia application ID
     * @param string $apiKey The Algolia API key
     *
     * @return SearchClient The configured SearchClient instance
     */
    #[Override]
    public function create(string $appId, string $apiKey): SearchClient
    {
        return SearchClient::create($appId, $apiKey);
    }
}
