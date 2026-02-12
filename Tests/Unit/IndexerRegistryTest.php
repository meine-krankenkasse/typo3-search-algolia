<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IndexerRegistry.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(IndexerRegistry::class)]
class IndexerRegistryTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the global configuration before each test
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer']);
    }

    /**
     * Tests that getRegisteredIndexers() returns an empty array when no indexers
     * have been registered. Verifies the default state of the registry is empty.
     */
    #[Test]
    public function getRegisteredIndexersReturnsEmptyArrayWhenNothingRegistered(): void
    {
        self::assertSame([], IndexerRegistry::getRegisteredIndexers());
    }

    /**
     * Tests that register() correctly adds an indexer entry to the global TYPO3
     * configuration. Verifies that after registration the indexers array contains
     * exactly one entry with the expected className, tableName, title, and icon values.
     */
    #[Test]
    public function registerAddsIndexerToGlobalConfiguration(): void
    {
        IndexerRegistry::register(
            PageIndexer::class,
            'pages',
            'Page Indexer',
            'apps-pagetree-page-default'
        );

        $indexers = IndexerRegistry::getRegisteredIndexers();

        self::assertCount(1, $indexers);
        self::assertSame(PageIndexer::class, $indexers[0]['className']);
        self::assertSame('pages', $indexers[0]['tableName']);
        self::assertSame('Page Indexer', $indexers[0]['title']);
        self::assertSame('apps-pagetree-page-default', $indexers[0]['icon']);
    }

    /**
     * Tests that register() stores a null icon value when no icon argument is
     * provided. Verifies the icon field in the registered indexer entry is null
     * when the optional icon parameter is omitted.
     */
    #[Test]
    public function registerWithNullIconStoresNullIcon(): void
    {
        IndexerRegistry::register(
            PageIndexer::class,
            'pages',
            'Page Indexer'
        );

        $indexers = IndexerRegistry::getRegisteredIndexers();

        self::assertCount(1, $indexers);
        self::assertNull($indexers[0]['icon']);
    }

    /**
     * Tests that calling register() multiple times accumulates all indexer entries
     * in the global configuration. Verifies that two successive registrations result
     * in an array of two entries with the correct table names in order.
     */
    #[Test]
    public function registerMultipleIndexersAccumulatesEntries(): void
    {
        IndexerRegistry::register(
            PageIndexer::class,
            'pages',
            'Page Indexer',
            'apps-pagetree-page-default'
        );

        IndexerRegistry::register(
            ContentIndexer::class,
            'tt_content',
            'Content Indexer',
            'content-text'
        );

        $indexers = IndexerRegistry::getRegisteredIndexers();

        self::assertCount(2, $indexers);
        self::assertSame('pages', $indexers[0]['tableName']);
        self::assertSame('tt_content', $indexers[1]['tableName']);
    }

    /**
     * Tests that getIndexerIcon() returns the correct icon identifier for a
     * registered table name. Verifies the icon string matches what was provided
     * during registration of the PageIndexer.
     */
    #[Test]
    public function getIndexerIconReturnsIconForRegisteredTable(): void
    {
        IndexerRegistry::register(
            PageIndexer::class,
            'pages',
            'Page Indexer',
            'apps-pagetree-page-default'
        );

        self::assertSame('apps-pagetree-page-default', IndexerRegistry::getIndexerIcon('pages'));
    }

    /**
     * Tests that getIndexerIcon() returns an empty string when the requested
     * table name does not match any registered indexer. Verifies the method
     * gracefully handles lookups for unknown table names.
     */
    #[Test]
    public function getIndexerIconReturnsEmptyStringForUnknownTable(): void
    {
        IndexerRegistry::register(
            PageIndexer::class,
            'pages',
            'Page Indexer',
            'apps-pagetree-page-default'
        );

        self::assertSame('', IndexerRegistry::getIndexerIcon('unknown_table'));
    }

    /**
     * Tests that getIndexerIcon() returns an empty string when the indexer
     * was registered without an icon (null icon value). Verifies that a null
     * icon is treated as an empty string on retrieval.
     */
    #[Test]
    public function getIndexerIconReturnsEmptyStringWhenIconIsNull(): void
    {
        IndexerRegistry::register(
            PageIndexer::class,
            'pages',
            'Page Indexer'
        );

        self::assertSame('', IndexerRegistry::getIndexerIcon('pages'));
    }

    /**
     * Tests that getIndexerIcon() returns an empty string when no indexers have
     * been registered at all. Verifies the method handles an empty registry
     * without errors.
     */
    #[Test]
    public function getIndexerIconReturnsEmptyStringWhenNoIndexersRegistered(): void
    {
        self::assertSame('', IndexerRegistry::getIndexerIcon('pages'));
    }

    /**
     * Tests that getRegisteredIndexers() returns an empty array when the
     * TYPO3_CONF_VARS global is not set at all. Verifies the method handles
     * a completely missing global configuration gracefully.
     */
    #[Test]
    public function getRegisteredIndexersReturnsEmptyArrayWhenGlobalNotSet(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);

        self::assertSame([], IndexerRegistry::getRegisteredIndexers());
    }

    /**
     * Tests that getRegisteredIndexers() returns an empty array when the global
     * configuration value for the indexer key is not an array. Verifies the method
     * handles invalid (non-array) configuration values safely.
     */
    #[Test]
    public function getRegisteredIndexersReturnsEmptyArrayWhenGlobalIsNotArray(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'] = 'not-an-array';

        self::assertSame([], IndexerRegistry::getRegisteredIndexers());
    }
}
