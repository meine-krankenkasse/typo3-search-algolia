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
}
