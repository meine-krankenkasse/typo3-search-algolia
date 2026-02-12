<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Domain\Model;

use DateTime;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for SearchEngine domain model.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(SearchEngine::class)]
class SearchEngineTest extends UnitTestCase
{
    /**
     * Tests that a newly constructed SearchEngine has its title property
     * initialized to an empty string. Verifies that getTitle() returns
     * an empty string when no value has been explicitly set.
     */
    #[Test]
    public function titleDefaultsToEmptyString(): void
    {
        $model = new SearchEngine();

        self::assertSame('', $model->getTitle());
    }

    /**
     * Tests that setTitle() correctly stores the provided string value.
     * Verifies that getTitle() returns the exact title string that was
     * passed to the setter.
     */
    #[Test]
    public function setTitleStoresValue(): void
    {
        $model = (new SearchEngine())->setTitle('Production Algolia');

        self::assertSame('Production Algolia', $model->getTitle());
    }

    /**
     * Tests that a newly constructed SearchEngine has its description property
     * initialized to an empty string. Verifies that getDescription() returns
     * an empty string when no value has been explicitly set.
     */
    #[Test]
    public function descriptionDefaultsToEmptyString(): void
    {
        $model = new SearchEngine();

        self::assertSame('', $model->getDescription());
    }

    /**
     * Tests that setDescription() correctly stores the provided string value.
     * Verifies that getDescription() returns the exact description string
     * that was passed to the setter.
     */
    #[Test]
    public function setDescriptionStoresValue(): void
    {
        $model = (new SearchEngine())->setDescription('Main search engine');

        self::assertSame('Main search engine', $model->getDescription());
    }

    /**
     * Tests that setEngine() correctly stores the provided engine identifier
     * string. Verifies that getEngine() returns the exact engine name that
     * was passed to the setter.
     */
    #[Test]
    public function setEngineStoresValue(): void
    {
        $model = (new SearchEngine())->setEngine('algolia');

        self::assertSame('algolia', $model->getEngine());
    }

    /**
     * Tests that setIndexName() correctly stores the provided index name
     * string. Verifies that getIndexName() returns the exact index name
     * that was passed to the setter.
     */
    #[Test]
    public function setIndexNameStoresValue(): void
    {
        $model = (new SearchEngine())->setIndexName('production_index');

        self::assertSame('production_index', $model->getIndexName());
    }

    /**
     * Tests that a newly constructed SearchEngine has its deleted property
     * initialized to false. Verifies that isDeleted() returns false when
     * no value has been explicitly set.
     */
    #[Test]
    public function deletedDefaultsToFalse(): void
    {
        $model = new SearchEngine();

        self::assertFalse($model->isDeleted());
    }

    /**
     * Tests that setDeleted() correctly stores the provided boolean value.
     * Verifies that isDeleted() returns true after setting the deleted
     * flag to true.
     */
    #[Test]
    public function setDeletedStoresValue(): void
    {
        $model = (new SearchEngine())->setDeleted(true);

        self::assertTrue($model->isDeleted());
    }

    /**
     * Tests that setCrdate() correctly stores the provided DateTime instance.
     * Verifies that getCrdate() returns the exact same DateTime object
     * reference that was passed to the setter.
     */
    #[Test]
    public function setCrdateStoresDateTime(): void
    {
        $dateTime = new DateTime('2024-01-01');
        $model    = (new SearchEngine())->setCrdate($dateTime);

        self::assertSame($dateTime, $model->getCrdate());
    }

    /**
     * Tests that setTstamp() correctly stores the provided DateTime instance.
     * Verifies that getTstamp() returns the exact same DateTime object
     * reference that was passed to the setter.
     */
    #[Test]
    public function setTstampStoresDateTime(): void
    {
        $dateTime = new DateTime('2024-06-15');
        $model    = (new SearchEngine())->setTstamp($dateTime);

        self::assertSame($dateTime, $model->getTstamp());
    }

    /**
     * Tests that all setter methods on SearchEngine can be chained together
     * in a fluent interface style. Verifies that after chaining setTitle(),
     * setDescription(), setEngine(), setIndexName(), setDeleted(), setCrdate(),
     * and setTstamp(), each corresponding getter returns the correct value.
     */
    #[Test]
    public function fluentInterfaceWorksForAllSetters(): void
    {
        $crdate = new DateTime('2024-01-01');
        $tstamp = new DateTime('2024-06-15');

        $model = (new SearchEngine())
            ->setTitle('Test Engine')
            ->setDescription('A test engine')
            ->setEngine('algolia')
            ->setIndexName('test_index')
            ->setDeleted(false)
            ->setCrdate($crdate)
            ->setTstamp($tstamp);

        self::assertSame('Test Engine', $model->getTitle());
        self::assertSame('A test engine', $model->getDescription());
        self::assertSame('algolia', $model->getEngine());
        self::assertSame('test_index', $model->getIndexName());
        self::assertFalse($model->isDeleted());
        self::assertSame($crdate, $model->getCrdate());
        self::assertSame($tstamp, $model->getTstamp());
    }
}
