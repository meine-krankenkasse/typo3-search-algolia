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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;
use function sort;

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
     * Tests that hasPageRecord() tells whether a record exists, and that a
     * deleted record only counts when the delete clause is switched off.
     */
    #[Test]
    public function hasPageRecordTellsWhetherARecordExists(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 2, 'deleted' => 1]);

        self::assertTrue($this->subject->hasPageRecord('pages', 2));
        self::assertFalse($this->subject->hasPageRecord('pages', 999));
        self::assertFalse($this->subject->hasPageRecord('pages', 20), 'A deleted record does not exist');
        self::assertTrue($this->subject->hasPageRecord('pages', 20, false), 'Unless deleted records are asked for');
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

    /**
     * Inserts a page below the given page.
     *
     * @param array<string, int|string> $fields The fields of the page, uid and pid are required
     *
     * @return void
     */
    private function insertPage(array $fields): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert('pages', $fields + ['title' => 'Page ' . $fields['uid'], 'doktype' => 1]);
    }

    /**
     * Tests that getDescendantPageIds() follows every type of page, hidden pages
     * and pages outside of their start and end time, and everything below them,
     * and leaves out deleted pages and the page itself.
     */
    #[Test]
    public function getDescendantPageIdsFollowsEveryTypeOfPage(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 3, 'hidden' => 1]);
        $this->insertPage(['uid' => 21, 'pid' => 20, 'starttime' => 4102444800]);
        $this->insertPage(['uid' => 22, 'pid' => 3, 'doktype' => 255]);
        $this->insertPage(['uid' => 23, 'pid' => 22, 'endtime' => 1000]);
        $this->insertPage(['uid' => 24, 'pid' => 3, 'deleted' => 1]);
        $this->insertPage(['uid' => 25, 'pid' => 24]);

        $descendants = $this->subject->getDescendantPageIds(2);

        sort($descendants);

        // 3 is the child of 2, 24 is deleted and so is everything below it
        self::assertSame([3, 20, 21, 22, 23], $descendants);
        self::assertSame([], $this->subject->getDescendantPageIds(23));
    }

    /**
     * Tests that getDescendantPageIds() leaves out a site root below the page and
     * everything below it when asked to stop at site roots, and that it follows
     * them by default.
     */
    #[Test]
    public function getDescendantPageIdsCanStopAtSiteRoots(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 3]);
        $this->insertPage(['uid' => 21, 'pid' => 20, 'is_siteroot' => 1]);
        $this->insertPage(['uid' => 22, 'pid' => 21]);
        $this->insertPage(['uid' => 23, 'pid' => 3]);

        $withSiteRoots    = $this->subject->getDescendantPageIds(2);
        $withoutSiteRoots = $this->subject->getDescendantPageIds(2, forSiteTree: true);

        sort($withSiteRoots);
        sort($withoutSiteRoots);

        self::assertSame([3, 20, 21, 22, 23], $withSiteRoots);
        self::assertSame([3, 20, 23], $withoutSiteRoots, 'The site root 21 and the page 22 below it are left out');
    }

    /**
     * Returns the sorted UIDs of the subpages below a page.
     *
     * @param int         $id    The UID of the starting page
     * @param int<0, max> $depth The depth of the walk
     *
     * @return int[]
     */
    private function sortedSubPageIds(
        int $id,
        int $depth,
    ): array {
        $pageIds = $this->subject->getSubPageIdsRecursive($id, $depth);

        sort($pageIds);

        return $pageIds;
    }

    /**
     * Tests the limits of getSubPageIdsRecursive(). The walk stops at the given depth,
     * and recycler pages, translations and the pages below them are left out.
     */
    #[Test]
    public function getSubPageIdsRecursiveStopsAtTheDepthAndLeavesOutRecyclerPagesAndTranslations(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 2]);
        $this->insertPage(['uid' => 21, 'pid' => 2]);
        $this->insertPage(['uid' => 22, 'pid' => 20]);
        $this->insertPage(['uid' => 23, 'pid' => 22]);
        $this->insertPage(['uid' => 24, 'pid' => 2, 'doktype' => 255]);
        $this->insertPage(['uid' => 25, 'pid' => 24]);
        $this->insertPage(['uid' => 26, 'pid' => 2, 'sys_language_uid' => 1, 'l10n_parent' => 20]);

        self::assertSame([3, 20, 21, 22, 23], $this->sortedSubPageIds(2, 99));
        self::assertSame([3, 20, 21, 22], $this->sortedSubPageIds(2, 2));
        self::assertSame([3, 20, 21], $this->sortedSubPageIds(2, 1));
        self::assertSame([], $this->sortedSubPageIds(2, 0));
        self::assertSame([3, 20, 21, 22, 23], $this->sortedSubPageIds(-2, 99), 'A negative UID is taken as its absolute value');
    }

    /**
     * Tests that getSubPageIdsRecursive() ends for data that is a ring, and that
     * it names every page once and not the page the walk started from.
     */
    #[Test]
    public function getSubPageIdsRecursiveEndsForADataRingThroughTheStartPage(): void
    {
        // Page 40 is its own parent, pages 41 and 42 are each other's parent
        $this->insertPage(['uid' => 40, 'pid' => 40]);
        $this->insertPage(['uid' => 41, 'pid' => 42]);
        $this->insertPage(['uid' => 42, 'pid' => 41]);

        self::assertSame([], $this->subject->getSubPageIdsRecursive(40, 99));
        self::assertSame([42], $this->subject->getSubPageIdsRecursive(41, 99));
    }

    /**
     * Tests that getSubPageIdsRecursive() leaves out the backend user section
     * pages and the pages below them, and answers nothing for a page UID of 0.
     */
    #[Test]
    public function getSubPageIdsRecursiveLeavesOutBackendUserSectionsAndTheRoot(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 2]);
        $this->insertPage(['uid' => 27, 'pid' => 2, 'doktype' => 6]);
        $this->insertPage(['uid' => 28, 'pid' => 27]);

        self::assertSame([3, 20], $this->subject->getSubPageIdsRecursive(2, 99));
        self::assertSame([], $this->subject->getSubPageIdsRecursive(0, 5));
    }

    /**
     * Tests that getSubPageIdsRecursive() answers the live pages, also for a user who
     * works in a workspace. The index holds live records, so a page that exists only in
     * a workspace is no page of the tree.
     */
    #[Test]
    public function getSubPageIdsRecursiveLeavesOutPagesThatExistOnlyInAWorkspace(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 3]);
        // A new page of workspace 1 has no live record
        $this->insertPage(['uid' => 21, 'pid' => 3, 't3ver_oid' => 0, 't3ver_wsid' => 1, 't3ver_state' => 1]);

        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(1));

        $pageIds = $this->subject->getSubPageIdsRecursive(2, 99);

        self::assertContains(20, $pageIds);
        self::assertNotContains(21, $pageIds);
    }

    /**
     * Tests that the descendant walk leaves out the workspace versions of pages,
     * which carry the parent of the live page and are no pages of the tree.
     */
    #[Test]
    public function getDescendantPageIdsLeavesOutWorkspaceVersions(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 3]);
        $this->insertPage(['uid' => 21, 'pid' => 3, 't3ver_oid' => 20, 't3ver_wsid' => 1, 't3ver_state' => 0]);

        $descendants = $this->subject->getDescendantPageIds(2);

        self::assertContains(20, $descendants);
        self::assertNotContains(21, $descendants);
    }

    /**
     * Tests that getDescendantPageIds() can leave out the translations of the
     * pages, which carry the parent of the page they translate and which a
     * move does not decide.
     */
    #[Test]
    public function getDescendantPageIdsCanLeaveOutTranslations(): void
    {
        $this->insertPage(['uid' => 20, 'pid' => 3]);
        $this->insertPage(['uid' => 21, 'pid' => 3, 'sys_language_uid' => 1, 'l10n_parent' => 20]);

        $all             = $this->subject->getDescendantPageIds(2);
        $defaultLanguage = $this->subject->getDescendantPageIds(2, forSiteTree: true);

        self::assertContains(21, $all, 'The translation is a descendant by default');
        self::assertContains(20, $defaultLanguage);
        self::assertNotContains(21, $defaultLanguage, 'The translation is left out on request');
    }

    /**
     * Tests that the descendant walk can stop at a page that is hidden, or not yet or no
     * longer published, and extends that state to its subpages. The page itself stays
     * in the answer, what lies below it is not reachable and is left out. A hidden page
     * that does not extend its state does not stop the walk.
     *
     * @param array<string, int|string> $fields  The fields of the page whose subpage is looked at
     * @param bool                      $reached Whether the subpage is part of the answer
     */
    #[Test]
    #[DataProvider('pagesThatMayExtendTheirState')]
    public function getDescendantPageIdsCanStopBelowAPageThatExtendsItsState(
        array $fields,
        bool $reached,
    ): void {
        $this->insertPage(['uid' => 20, 'pid' => 3, ...$fields]);
        $this->insertPage(['uid' => 21, 'pid' => 20]);

        $stopped = $this->subject->getDescendantPageIds(2, forSiteTree: true);
        $all     = $this->subject->getDescendantPageIds(2);

        self::assertContains(20, $stopped, 'The page itself is part of the answer');
        self::assertContains(21, $all, 'The walk follows the subpage by default');
        self::assertSame($reached, in_array(21, $stopped, true));
    }

    /**
     * Provides pages with and without a state that is extended to the subpages.
     *
     * @return iterable<string, array{array<string, int|string>, bool}>
     */
    public static function pagesThatMayExtendTheirState(): iterable
    {
        yield 'hidden and extending' => [['hidden' => 1, 'extendToSubpages' => 1], false];
        yield 'hidden, not extending' => [['hidden' => 1, 'extendToSubpages' => 0], true];
        yield 'visible and extending' => [['hidden' => 0, 'extendToSubpages' => 1], true];
        yield 'not yet published, extending' => [['starttime' => 4102444800, 'extendToSubpages' => 1], false];
        yield 'no longer published, extending' => [['endtime' => 1000, 'extendToSubpages' => 1], false];
    }

    /**
     * Tests that the start page itself can hide what lies below it, which is the moved
     * page when a tree is moved.
     */
    #[Test]
    public function getDescendantPageIdsAnswersNothingBelowAStartPageThatExtendsItsHiddenState(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['hidden' => 1, 'extendToSubpages' => 1], ['uid' => 2]);

        self::assertSame([], $this->subject->getDescendantPageIds(2, forSiteTree: true));
        self::assertContains(3, $this->subject->getDescendantPageIds(2), 'The walk of a delete follows it');
    }

    /**
     * Tests that getDescendantPageIds() ends for data that is a cycle, and that
     * it finds the pages of a level that is wider than one block of a query. The
     * test database takes the whole list in one statement, so this pins the answer
     * and not the size of the block.
     */
    #[Test]
    public function getDescendantPageIdsEndsForACycleAndAnswersForAWideLevel(): void
    {
        // Pages 40, 41 and 42 are each other's parent in a ring
        $this->insertPage(['uid' => 40, 'pid' => 41]);
        $this->insertPage(['uid' => 41, 'pid' => 3]);
        $this->insertPage(['uid' => 42, 'pid' => 40]);
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 42], ['uid' => 41]);

        $cycle = $this->subject->getDescendantPageIds(40);

        sort($cycle);

        self::assertSame([41, 42], $cycle, 'Every page once, and not the page the walk started from');

        // A level of more pages than one block, and one page below the first and one below the
        // last of them, so that the children of an earlier block must not be lost
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');

        for ($uid = 5000; $uid < 6100; ++$uid) {
            $connection->insert('pages', ['uid' => $uid, 'pid' => 4, 'title' => 'Wide ' . $uid, 'doktype' => 1]);
        }

        $this->insertPage(['uid' => 7000, 'pid' => 5000]);
        $this->insertPage(['uid' => 7001, 'pid' => 6099]);

        $wide = $this->subject->getDescendantPageIds(4);

        self::assertCount(1102, $wide, '1100 wide pages plus the two below the first and the last');
        self::assertContains(7000, $wide, 'The page below the first page of the wide level');
        self::assertContains(7001, $wide, 'The page below the last page of the wide level');
    }
}
