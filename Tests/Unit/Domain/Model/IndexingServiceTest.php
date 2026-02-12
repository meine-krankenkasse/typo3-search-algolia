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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for IndexingService domain model.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(IndexingService::class)]
#[UsesClass(SearchEngine::class)]
class IndexingServiceTest extends UnitTestCase
{
    /**
     * Tests that a newly constructed IndexingService has its hidden property
     * initialized to false.
     */
    #[Test]
    public function hiddenDefaultsToFalse(): void
    {
        $model = new IndexingService();

        self::assertFalse($model->isHidden());
    }

    /**
     * Tests that setHidden() correctly stores the provided boolean value
     * and isHidden() returns true after setting it.
     */
    #[Test]
    public function setHiddenStoresValue(): void
    {
        $model = (new IndexingService())->setHidden(true);

        self::assertTrue($model->isHidden());
    }

    /**
     * Tests that a newly constructed IndexingService has its deleted property
     * initialized to false.
     */
    #[Test]
    public function deletedDefaultsToFalse(): void
    {
        $model = new IndexingService();

        self::assertFalse($model->isDeleted());
    }

    /**
     * Tests that setDeleted() correctly stores the provided boolean value
     * and isDeleted() returns true after setting it.
     */
    #[Test]
    public function setDeletedStoresValue(): void
    {
        $model = (new IndexingService())->setDeleted(true);

        self::assertTrue($model->isDeleted());
    }

    /**
     * Tests that a newly constructed IndexingService has its title property
     * initialized to an empty string.
     */
    #[Test]
    public function titleDefaultsToEmptyString(): void
    {
        $model = new IndexingService();

        self::assertSame('', $model->getTitle());
    }

    /**
     * Tests that setTitle() correctly stores the provided string value
     * and getTitle() returns the exact string that was set.
     */
    #[Test]
    public function setTitleStoresValue(): void
    {
        $model = (new IndexingService())->setTitle('Index all pages');

        self::assertSame('Index all pages', $model->getTitle());
    }

    /**
     * Tests that a newly constructed IndexingService has its description property
     * initialized to an empty string.
     */
    #[Test]
    public function descriptionDefaultsToEmptyString(): void
    {
        $model = new IndexingService();

        self::assertSame('', $model->getDescription());
    }

    /**
     * Tests that setDescription() correctly stores the provided string value
     * and getDescription() returns the exact string that was set.
     */
    #[Test]
    public function setDescriptionStoresValue(): void
    {
        $model = (new IndexingService())->setDescription('Indexes all standard pages');

        self::assertSame('Indexes all standard pages', $model->getDescription());
    }

    /**
     * Tests that setType() correctly stores the provided content type string
     * and getType() returns the exact string that was set.
     */
    #[Test]
    public function setTypeStoresValue(): void
    {
        $model = (new IndexingService())->setType('pages');

        self::assertSame('pages', $model->getType());
    }

    /**
     * Tests that setSearchEngine() correctly stores the provided SearchEngine instance
     * and getSearchEngine() returns the exact same object reference.
     */
    #[Test]
    public function setSearchEngineStoresValue(): void
    {
        $searchEngine = (new SearchEngine())
            ->setEngine('algolia')
            ->setIndexName('production_index');

        $model = (new IndexingService())->setSearchEngine($searchEngine);

        self::assertSame($searchEngine, $model->getSearchEngine());
    }

    /**
     * Tests that setIncludeContentElements() correctly stores the provided boolean value
     * and isIncludeContentElements() returns true after setting it.
     */
    #[Test]
    public function setIncludeContentElementsStoresValue(): void
    {
        $model = (new IndexingService())->setIncludeContentElements(true);

        self::assertTrue($model->isIncludeContentElements());
    }

    /**
     * Tests that a newly constructed IndexingService has its contentElementTypes property
     * initialized to an empty string.
     */
    #[Test]
    public function contentElementTypesDefaultsToEmptyString(): void
    {
        $model = new IndexingService();

        self::assertSame('', $model->getContentElementTypes());
    }

    /**
     * Tests that setContentElementTypes() correctly stores the provided comma-separated
     * string and getContentElementTypes() returns the exact string that was set.
     */
    #[Test]
    public function setContentElementTypesStoresValue(): void
    {
        $model = (new IndexingService())->setContentElementTypes('text,textpic,image');

        self::assertSame('text,textpic,image', $model->getContentElementTypes());
    }

    /**
     * Tests that a newly constructed IndexingService has its pagesDoktype property
     * initialized to an empty string.
     */
    #[Test]
    public function pagesDoktypeDefaultsToEmptyString(): void
    {
        $model = new IndexingService();

        self::assertSame('', $model->getPagesDoktype());
    }

    /**
     * Tests that setPagesDoktype() correctly stores the provided comma-separated
     * string and getPagesDoktype() returns the exact string that was set.
     */
    #[Test]
    public function setPagesDokTypeStoresValue(): void
    {
        $model = (new IndexingService())->setPagesDoktype('1,4');

        self::assertSame('1,4', $model->getPagesDoktype());
    }

    /**
     * Tests that a newly constructed IndexingService has its pagesSingle property
     * initialized to an empty string.
     */
    #[Test]
    public function pagesSingleDefaultsToEmptyString(): void
    {
        $model = new IndexingService();

        self::assertSame('', $model->getPagesSingle());
    }

    /**
     * Tests that setPagesSingle() correctly stores the provided comma-separated
     * string and getPagesSingle() returns the exact string that was set.
     */
    #[Test]
    public function setPagesSingleStoresValue(): void
    {
        $model = (new IndexingService())->setPagesSingle('42,56,78');

        self::assertSame('42,56,78', $model->getPagesSingle());
    }

    /**
     * Tests that a newly constructed IndexingService has its pagesRecursive property
     * initialized to an empty string.
     */
    #[Test]
    public function pagesRecursiveDefaultsToEmptyString(): void
    {
        $model = new IndexingService();

        self::assertSame('', $model->getPagesRecursive());
    }

    /**
     * Tests that setPagesRecursive() correctly stores the provided comma-separated
     * string and getPagesRecursive() returns the exact string that was set.
     */
    #[Test]
    public function setPagesRecursiveStoresValue(): void
    {
        $model = (new IndexingService())->setPagesRecursive('1,42');

        self::assertSame('1,42', $model->getPagesRecursive());
    }

    /**
     * Tests that a newly constructed IndexingService has its fileCollections property
     * initialized to an empty string.
     */
    #[Test]
    public function fileCollectionsDefaultsToEmptyString(): void
    {
        $model = new IndexingService();

        self::assertSame('', $model->getFileCollections());
    }

    /**
     * Tests that setFileCollections() correctly stores the provided comma-separated
     * string and getFileCollections() returns the exact string that was set.
     */
    #[Test]
    public function setFileCollectionsStoresValue(): void
    {
        $model = (new IndexingService())->setFileCollections('3,7,12');

        self::assertSame('3,7,12', $model->getFileCollections());
    }

    /**
     * Tests that setCrdate() correctly stores the provided DateTime instance
     * and getCrdate() returns the exact same DateTime object reference.
     */
    #[Test]
    public function setCrdateStoresDateTime(): void
    {
        $dateTime = new DateTime('2024-01-01');
        $model    = (new IndexingService())->setCrdate($dateTime);

        self::assertSame($dateTime, $model->getCrdate());
    }

    /**
     * Tests that setTstamp() correctly stores the provided DateTime instance
     * and getTstamp() returns the exact same DateTime object reference.
     */
    #[Test]
    public function setTstampStoresDateTime(): void
    {
        $dateTime = new DateTime('2024-06-15');
        $model    = (new IndexingService())->setTstamp($dateTime);

        self::assertSame($dateTime, $model->getTstamp());
    }

    /**
     * Tests that all setter methods on IndexingService can be chained together
     * in a fluent interface style. Verifies that after chaining all setters,
     * each corresponding getter returns the correct value.
     */
    #[Test]
    public function fluentInterfaceWorksForAllSetters(): void
    {
        $crdate       = new DateTime('2024-01-01');
        $tstamp       = new DateTime('2024-06-15');
        $searchEngine = new SearchEngine();

        $model = (new IndexingService())
            ->setCrdate($crdate)
            ->setTstamp($tstamp)
            ->setHidden(false)
            ->setDeleted(false)
            ->setTitle('Index pages')
            ->setDescription('Indexes all standard pages')
            ->setType('pages')
            ->setSearchEngine($searchEngine)
            ->setIncludeContentElements(true)
            ->setContentElementTypes('text,textpic')
            ->setPagesDoktype('1,4')
            ->setPagesSingle('42')
            ->setPagesRecursive('1')
            ->setFileCollections('3,7');

        self::assertSame($crdate, $model->getCrdate());
        self::assertSame($tstamp, $model->getTstamp());
        self::assertFalse($model->isHidden());
        self::assertFalse($model->isDeleted());
        self::assertSame('Index pages', $model->getTitle());
        self::assertSame('Indexes all standard pages', $model->getDescription());
        self::assertSame('pages', $model->getType());
        self::assertSame($searchEngine, $model->getSearchEngine());
        self::assertTrue($model->isIncludeContentElements());
        self::assertSame('text,textpic', $model->getContentElementTypes());
        self::assertSame('1,4', $model->getPagesDoktype());
        self::assertSame('42', $model->getPagesSingle());
        self::assertSame('1', $model->getPagesRecursive());
        self::assertSame('3,7', $model->getFileCollections());
    }
}
