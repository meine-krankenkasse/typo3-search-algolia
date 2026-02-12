<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit;

use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchEngineRegistry.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(SearchEngineRegistry::class)]
class SearchEngineRegistryTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        unset($GLOBALS['T3_SERVICES']['mkk_search_engine']);
    }

    /**
     * Tests that getRegisteredSearchEngines() returns an empty array when no
     * search engines have been registered. Verifies the default state of the
     * registry is empty.
     */
    #[Test]
    public function getRegisteredSearchEnginesReturnsEmptyArrayWhenNothingRegistered(): void
    {
        self::assertSame([], SearchEngineRegistry::getRegisteredSearchEngines());
    }

    /**
     * Tests that getRegisteredSearchEngines() returns the exact array of search
     * engine entries that was stored in the T3_SERVICES global. Verifies the method
     * correctly reads and returns the registered engines including className and subtype.
     */
    #[Test]
    public function getRegisteredSearchEnginesReturnsRegisteredEngines(): void
    {
        $engines = [
            'algolia' => [
                'className' => 'MeineKrankenkasse\\Typo3SearchAlgolia\\Service\\AlgoliaSearchEngine',
                'subtype'   => 'algolia',
            ],
        ];

        $GLOBALS['T3_SERVICES']['mkk_search_engine'] = $engines;

        self::assertSame($engines, SearchEngineRegistry::getRegisteredSearchEngines());
    }

    /**
     * Tests that getRegisteredSearchEngines() returns an empty array when the
     * T3_SERVICES global is not set at all. Verifies the method handles a
     * completely missing global configuration gracefully.
     */
    #[Test]
    public function getRegisteredSearchEnginesReturnsEmptyArrayWhenGlobalNotSet(): void
    {
        unset($GLOBALS['T3_SERVICES']);

        self::assertSame([], SearchEngineRegistry::getRegisteredSearchEngines());
    }

    /**
     * Tests that getRegisteredSearchEngines() returns an empty array when the
     * global configuration value for the search engine key is not an array.
     * Verifies the method handles invalid (non-array) configuration values safely.
     */
    #[Test]
    public function getRegisteredSearchEnginesReturnsEmptyArrayWhenGlobalIsNotArray(): void
    {
        $GLOBALS['T3_SERVICES']['mkk_search_engine'] = 'not-an-array';

        self::assertSame([], SearchEngineRegistry::getRegisteredSearchEngines());
    }
}
