<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for CategoryRepository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(CategoryRepository::class)]
final class CategoryRepositoryTest extends AbstractFunctionalTestCase
{
    private CategoryRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_record_mm.csv');

        $this->subject = new CategoryRepository($this->getConnectionPool());
    }

    /**
     * Tests that findAssignedToRecord() returns the category UIDs
     * assigned to a page record via the sys_category_record_mm table.
     */
    #[Test]
    public function findAssignedToRecordReturnsCategoriesForPage(): void
    {
        $categories = $this->subject->findAssignedToRecord('pages', 2);

        self::assertCount(2, $categories);
    }

    /**
     * Tests that findAssignedToRecord() returns an empty array when
     * the page has no category assignments in the MM table.
     */
    #[Test]
    public function findAssignedToRecordReturnsEmptyForPageWithoutCategories(): void
    {
        $categories = $this->subject->findAssignedToRecord('pages', 3);

        self::assertSame([], $categories);
    }

    /**
     * Tests that findByUid() returns the full category record
     * including its title for an existing category UID.
     */
    #[Test]
    public function findByUidReturnsCategoryRecord(): void
    {
        $category = $this->subject->findByUid(1);

        self::assertIsArray($category);
        self::assertSame('Category A', $category['title']);
    }

    /**
     * Tests that findByUid() returns false when no category
     * exists with the given UID in the database.
     */
    #[Test]
    public function findByUidReturnsFalseForNonExistentCategory(): void
    {
        $category = $this->subject->findByUid(99999);

        self::assertFalse($category);
    }

    /**
     * Tests that hasCategoryReference() returns true when the record
     * has at least one matching category reference in the MM table.
     */
    #[Test]
    public function hasCategoryReferenceReturnsTrueWhenReferenceExists(): void
    {
        $result = $this->subject->hasCategoryReference(2, 'pages', [1, 2]);

        self::assertTrue($result);
    }

    /**
     * Tests that hasCategoryReference() returns false when the record
     * has no matching category references in the MM table.
     */
    #[Test]
    public function hasCategoryReferenceReturnsFalseWhenNoReferenceExists(): void
    {
        $result = $this->subject->hasCategoryReference(3, 'pages', [1, 2]);

        self::assertFalse($result);
    }

    /**
     * Tests that hasCategoryReference() returns false when an empty
     * array of category UIDs is provided.
     */
    #[Test]
    public function hasCategoryReferenceReturnsFalseForEmptyCategoryUids(): void
    {
        $result = $this->subject->hasCategoryReference(2, 'pages', []);

        self::assertFalse($result);
    }

    /**
     * Tests that findAssignedToRecord() filters by an explicit fieldname
     * instead of always matching the default 'categories' relation.
     */
    #[Test]
    public function findAssignedToRecordFiltersByCustomFieldName(): void
    {
        $categories = $this->subject->findAssignedToRecord(
            'pages',
            2,
            'topics',
        );

        self::assertCount(1, $categories);
        self::assertSame(1, $categories[0]['uid']);
        self::assertSame('Category A', $categories[0]['title']);
    }

    /**
     * Tests that the default fieldname argument still only returns the
     * 'categories'-tagged relations, unaffected by other fieldnames on the
     * same record.
     */
    #[Test]
    public function findAssignedToRecordDefaultFieldNameIgnoresOtherFieldNames(): void
    {
        $categories = $this->subject->findAssignedToRecord(
            'pages',
            2,
        );

        self::assertCount(2, $categories);
        self::assertSame('Category A', $categories[0]['title']);
        self::assertSame('Category B', $categories[1]['title']);
    }

    /**
     * Tests that findAssignedToRecord() returns an empty array when
     * a custom fieldname has no category associations.
     */
    #[Test]
    public function findAssignedToRecordReturnsEmptyForCustomFieldNameWithNoMatches(): void
    {
        $categories = $this->subject->findAssignedToRecord(
            'pages',
            2,
            'nonexistent_fieldname',
        );

        self::assertSame([], $categories);
    }

    /**
     * Collects the records the given categories are assigned to through any
     * MM fieldname, grouped by table and without duplicates.
     */
    #[Test]
    public function findRecordUidsByCategoriesGroupsAssignedRecordsByTable(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_record_mm_multiple_tables.csv');

        self::assertSame(
            [
                'pages'             => [2, 3],
                'sys_file_metadata' => [10],
            ],
            $this->subject->findRecordUidsByCategories([5]),
        );
    }

    /**
     * The records of several categories are merged, a record assigned to more
     * than one of them (or twice to one, e.g. as category and as topic) is
     * listed only once.
     */
    #[Test]
    public function findRecordUidsByCategoriesListsARecordOnlyOnce(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_record_mm_multiple_tables.csv');

        self::assertSame(
            [
                'pages'             => [2, 3, 4],
                'sys_file_metadata' => [10],
            ],
            $this->subject->findRecordUidsByCategories([1, 5, 6]),
        );
    }

    /**
     * Categories without assigned records, or no categories at all, yield an empty result.
     */
    #[Test]
    public function findRecordUidsByCategoriesReturnsEmptyForUnusedCategories(): void
    {
        self::assertSame([], $this->subject->findRecordUidsByCategories([99]));
        self::assertSame([], $this->subject->findRecordUidsByCategories([]));
    }

    /**
     * Returns the children and grandchildren of a category, but not deleted,
     * hidden, not yet started or expired ones (nor anything below them), not the
     * category itself and not unrelated categories.
     */
    #[Test]
    public function findDescendantUidsReturnsVisibleSubcategories(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_tree.csv');

        self::assertSame([21, 22, 24], $this->subject->findDescendantUids(20));
        self::assertSame([24], $this->subject->findDescendantUids(22));
        self::assertSame([], $this->subject->findDescendantUids(24));
    }

    /**
     * A cyclic parent reference does not make the lookup loop forever, and the
     * category itself is never reported as its own descendant.
     */
    #[Test]
    public function findDescendantUidsTerminatesOnACyclicTree(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_cycle.csv');

        self::assertSame([31], $this->subject->findDescendantUids(30));
    }
}
