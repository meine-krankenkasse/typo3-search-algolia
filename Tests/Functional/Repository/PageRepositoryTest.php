<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;

/**
 * Functional tests for PageRepository.
 *
 * Tests page tree operations with real database queries: getRootPageId,
 * getPageIdsRecursive, getPageRecord, findTitle.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(PageRepository::class)]
final class PageRepositoryTest extends AbstractFunctionalTestCase
{
    private PageRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');

        $this->subject = new PageRepository($this->getConnectionPool());
    }

    /**
     * Tests that getRootPageId() returns the root page UID for
     * a page that is a direct child of the root page.
     */
    #[Test]
    public function getRootPageIdReturnsRootForDirectChild(): void
    {
        $rootPageId = $this->subject->getRootPageId(2);

        self::assertSame(1, $rootPageId);
    }

    /**
     * Tests that getRootPageId() returns the root page UID for a
     * deeply nested page by traversing the page tree upward.
     */
    #[Test]
    public function getRootPageIdReturnsRootForDeepPage(): void
    {
        $rootPageId = $this->subject->getRootPageId(3);

        self::assertSame(1, $rootPageId);
    }

    /**
     * Tests that getRootPageId() throws a PageNotFoundException
     * when the specified page does not exist in the database.
     */
    #[Test]
    public function getRootPageIdThrowsExceptionForNonExistentPage(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->subject->getRootPageId(999);
    }

    /**
     * Tests that getRootPageId() returns the page's own UID when
     * the page itself is the root page (pid=0).
     */
    #[Test]
    public function getRootPageIdReturnsRootPageItself(): void
    {
        $rootPageId = $this->subject->getRootPageId(1);

        self::assertSame(1, $rootPageId);
    }

    /**
     * Tests that getPageIdsRecursive() returns all sub-pages within
     * the page tree, excluding recycler pages (doktype=255).
     */
    #[Test]
    public function getPageIdsRecursiveReturnsAllSubPages(): void
    {
        $pageIds = $this->subject->getPageIdsRecursive([1], 99);

        sort($pageIds);

        // Should include root (1), sub (2), deep (3), hidden (4) but not recycler (5, doktype=255)
        self::assertContains(1, $pageIds);
        self::assertContains(2, $pageIds);
        self::assertContains(3, $pageIds);
        self::assertNotContains(5, $pageIds);
    }

    /**
     * Tests that getPageIdsRecursive() respects the depth parameter
     * and only returns pages within one level below the starting pages.
     */
    #[Test]
    public function getPageIdsRecursiveRespectsDepthOne(): void
    {
        $pageIds = $this->subject->getPageIdsRecursive([1], 1);

        sort($pageIds);

        // Depth=1: root (1) + direct children (2, 4) but not deep (3)
        self::assertContains(1, $pageIds);
        self::assertContains(2, $pageIds);
        self::assertNotContains(3, $pageIds);
    }

    /**
     * Tests that getPageIdsRecursive() excludes hidden pages from
     * the result when the excludeHidden parameter is enabled.
     */
    #[Test]
    public function getPageIdsRecursiveExcludesHiddenPages(): void
    {
        $pageIds = $this->subject->getPageIdsRecursive([1], 99, true, true);

        // Hidden page (uid=4) should be excluded
        self::assertNotContains(4, $pageIds);
        // Non-hidden pages should still be present
        self::assertContains(2, $pageIds);
    }

    /**
     * Tests that getPageIdsRecursive() always excludes recycler
     * pages (doktype=255) from the result set.
     */
    #[Test]
    public function getPageIdsRecursiveExcludesRecycler(): void
    {
        $pageIds = $this->subject->getPageIdsRecursive([1], 99);

        // Recycler (uid=5, doktype=255) always excluded
        self::assertNotContains(5, $pageIds);
    }

    /**
     * Tests that getPageIdsRecursive() returns an empty array
     * when given an empty array of starting page IDs.
     */
    #[Test]
    public function getPageIdsRecursiveReturnsEmptyForEmptyInput(): void
    {
        $pageIds = $this->subject->getPageIdsRecursive([], 99);

        self::assertSame([], $pageIds);
    }

    /**
     * Tests that getPageIdsRecursive() returns only the input page IDs
     * without recursion when the depth parameter is zero.
     */
    #[Test]
    public function getPageIdsRecursiveReturnsInputForDepthZero(): void
    {
        $pageIds = $this->subject->getPageIdsRecursive([1, 2], 0);

        self::assertSame([1, 2], $pageIds);
    }

    /**
     * Tests that getPageRecord() returns the full record data
     * including UID and title for an existing page.
     */
    #[Test]
    public function getPageRecordReturnsRecordData(): void
    {
        $record = $this->subject->getPageRecord('pages', 2);

        self::assertNotEmpty($record);
        self::assertSame(2, (int) $record['uid']);
        self::assertSame('Sub Page', $record['title']);
    }

    /**
     * Tests that getPageRecord() returns an empty array when the
     * specified page does not exist in the database.
     */
    #[Test]
    public function getPageRecordReturnsEmptyForNonExistent(): void
    {
        $record = $this->subject->getPageRecord('pages', 999);

        self::assertSame([], $record);
    }

    /**
     * Tests that findTitle() returns the title string for an
     * existing page record identified by its UID.
     */
    #[Test]
    public function findTitleReturnsPageTitle(): void
    {
        $title = $this->subject->findTitle(2);

        self::assertSame('Sub Page', $title);
    }

    /**
     * Tests that findTitle() returns an empty string when the
     * specified page does not exist in the database.
     */
    #[Test]
    public function findTitleReturnsEmptyForNonExistent(): void
    {
        $title = $this->subject->findTitle(999);

        self::assertSame('', $title);
    }
}
