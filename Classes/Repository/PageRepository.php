<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Utility\PublicationWindow;
use Override;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository as CorePageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

use function array_chunk;
use function array_values;

/**
 * Repository for accessing and navigating TYPO3 page structures.
 *
 * This repository provides methods for working with TYPO3 pages and page trees:
 * - Retrieving pages recursively through the page tree
 * - Finding root pages for specific page subtrees
 * - Navigating page hierarchies for indexing operations
 *
 * The repository is essential for the indexing system to determine which pages
 * should be included in indexing operations and to establish the proper context
 * for indexed content (e.g., which site a page belongs to).
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class PageRepository implements PageRepositoryInterface
{
    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections needed for
     * retrieving pages and navigating page hierarchies.
     *
     * @param ConnectionPool $connectionPool The TYPO3 database connection pool
     */
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Retrieves the title of a specific page.
     *
     * This method fetches the 'title' field of a page record identified by its UID.
     * It is used for displaying human-readable page titles in various backend
     * modules and during indexing processes.
     *
     * @param int $uid The unique identifier of the page record
     *
     * @return string The title of the page, or an empty string if the page doesn't exist
     */
    #[Override]
    public function findTitle(int $uid): string
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('pages');

        $page = $queryBuilder
            ->select('title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $uid,
                        Connection::PARAM_INT
                    )
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        return (string) ($page['title'] ?? '');
    }

    /**
     * Retrieves a database record for a given table and record UID.
     *
     * This method fetches the data for a specific record from the specified table.
     * It allows selecting specific fields, applying a delete clause, and ensures
     * that the retrieved data is returned as an array.
     *
     * @param string $tableName       The name of the database table to fetch the record from
     * @param int    $recordUid       The UID of the record to retrieve
     * @param string $fields          The fields to select in the query, defaults to '*'
     * @param bool   $useDeleteClause Whether to include a clause for filtering deleted records, defaults to true
     *
     * @return array<string, int|string|null> An associative array containing the record data, or an empty array if the record is not found
     */
    #[Override]
    public function getPageRecord(
        string $tableName,
        int $recordUid,
        string $fields = '*',
        bool $useDeleteClause = true,
    ): array {
        return BackendUtility::getRecord(
            $tableName,
            $recordUid,
            $fields,
            '',
            $useDeleteClause
        ) ?? [];
    }

    /**
     * Tells whether a record of a table exists.
     *
     * A record that is deleted only exists when the delete clause is switched off.
     *
     * @param string $tableName       The name of the database table of the record
     * @param int    $recordUid       The UID of the record
     * @param bool   $useDeleteClause Whether a deleted record counts as not existing, defaults to true
     *
     * @return bool TRUE if the record exists
     */
    #[Override]
    public function hasPageRecord(
        string $tableName,
        int $recordUid,
        bool $useDeleteClause = true,
    ): bool {
        return $this->getPageRecord($tableName, $recordUid, 'uid', $useDeleteClause) !== [];
    }

    /**
     * Recursively fetches all pages starting from the given page IDs.
     *
     * This method retrieves a complete list of page IDs by starting with the
     * provided page IDs and then recursively traversing down the page tree
     * to include all subpages up to the specified depth. It offers options to:
     * - Include or exclude the starting pages themselves
     * - Include or exclude hidden pages from the results
     *
     * The method is primarily used by indexers to determine which pages should
     * be included in indexing operations based on the indexing service configuration.
     * It's essential for implementing the "recursive page selection" feature in
     * indexing services.
     *
     * @param int[]       $pageIds              A list of page IDs for which the subpages will be recursively determined
     * @param int<0, max> $depth                The recursive iteration depth (0 means no recursion, just the starting pages)
     * @param bool        $includeAncestorPages Set to TRUE to include the given page IDs in the result
     * @param bool        $excludeHiddenPages   Set to TRUE to exclude hidden subpages from the result
     *
     * @return int[] Array of page UIDs that match the criteria
     *
     * @throws Exception If a database error occurs during the query
     */
    #[Override]
    public function getPageIdsRecursive(
        array $pageIds,
        int $depth,
        bool $includeAncestorPages = true,
        bool $excludeHiddenPages = false,
    ): array {
        if ($pageIds === []) {
            return [];
        }

        if ($depth === 0) {
            return $pageIds;
        }

        $recursivePageIds = [];

        if ($includeAncestorPages) {
            $recursivePageIds[] = $pageIds;
        }

        foreach ($pageIds as $pageId) {
            $recursivePageIds[] = $this->getSubPageIdsRecursive(
                $pageId,
                $depth,
                $excludeHiddenPages
            );
        }

        return array_unique(
            array_merge(...$recursivePageIds)
        );
    }

    /**
     * Recursively fetches all descendant pages of a given page.
     *
     * This method traverses the page tree starting from a specific page and
     * collects all subpages down to the specified depth. It automatically:
     * - Excludes the starting page itself from the results
     * - Filters out system pages (recycler and backend user section pages)
     * - Respects workspace and deletion restrictions
     * - Optionally filters out hidden pages
     *
     * The method is an optimized replacement for TYPO3's deprecated QueryGenerator
     * functionality and is used internally by getPageIdsRecursive() to perform
     * the actual recursive page retrieval.
     *
     * @param int         $id                 The UID of the starting page
     * @param int<0, max> $depth              The recursive iteration depth (0 means no recursion)
     * @param bool        $excludeHiddenPages Set to TRUE to exclude hidden subpages from the result
     *
     * @return int[] Array of page UIDs that are descendants of the starting page
     *
     * @throws Exception If a database error occurs during the query
     */
    public function getSubPageIdsRecursive(int $id, int $depth, bool $excludeHiddenPages = false): array
    {
        if ($depth === 0) {
            return [];
        }

        if ($id < 0) {
            $id = abs($id);
        }

        if (
            ($id <= 0)
            || ($depth < 0)
        ) {
            return [];
        }

        // The pages are read level by level, with one query per level and block of
        // parents and not one per page
        $pageIds = [];
        $level   = [$id];

        for ($currentDepth = 0; ($currentDepth < $depth) && ($level !== []); ++$currentDepth) {
            $nextLevel = [];

            foreach (array_chunk($level, Constants::IN_LIST_BLOCK_SIZE) as $chunk) {
                $queryBuilder = $this->connectionPool
                    ->getQueryBuilderForTable('pages');

                $queryBuilder->getRestrictions()
                    ->removeAll()
                    ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                    ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class));

                if ($excludeHiddenPages) {
                    $queryBuilder->getRestrictions()
                        ->add(GeneralUtility::makeInstance(HiddenRestriction::class));
                }

                $rows = $queryBuilder
                    ->select('uid', 'pid')
                    ->from('pages')
                    ->where(
                        $queryBuilder->expr()->in(
                            'pid',
                            $queryBuilder->createNamedParameter($chunk, ArrayParameterType::INTEGER)
                        ),
                        $queryBuilder->expr()->eq(
                            'sys_language_uid',
                            0
                        ),
                        // Exclude some page types, see \TYPO3\CMS\Core\Domain\Repository\PageRepository::getSubpagesRecursive
                        $queryBuilder->expr()->notIn(
                            'doktype',
                            [
                                CorePageRepository::DOKTYPE_RECYCLER,
                                CorePageRepository::DOKTYPE_BE_USER_SECTION,
                            ]
                        )
                    )
                    ->orderBy('uid')
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($rows as $row) {
                    // A page has one parent, so a ring can only lead back to the page the
                    // walk started from, and that page is not part of the answer
                    if (((int) $row['uid']) === $id) {
                        continue;
                    }

                    $pageIds[]   = (int) $row['uid'];
                    $nextLevel[] = (int) $row['uid'];
                }
            }

            $level = $nextLevel;
        }

        return $pageIds;
    }

    /**
     * Determines the root page (site root) for any page in the TYPO3 page tree.
     *
     * This method traverses up the page tree from the given page to find the
     * site root page (a page marked with the "is_siteroot" flag). This information
     * is critical for:
     * - Determining which indexing services apply to a specific page
     * - Establishing the correct site context for indexed content
     * - Grouping indexed content by site
     *
     * The method uses TYPO3's RootlineUtility to efficiently retrieve the page's
     * rootline (path from the page to the root of the tree) and then identifies
     * the site root within that path.
     *
     * @param int $pageId The page ID to find the root page for
     *
     * @return int The UID of the root page, or 0 if no root page could be determined
     */
    #[Override]
    public function getRootPageId(int $pageId): int
    {
        // TODO Could possibly replaced with a "WITH RECURSIVE" SQL call
        // @see https://dba.stackexchange.com/a/291328

        $rootLineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $pageId);
        $rootLines       = $rootLineUtility->get();

        foreach ($rootLines as $rootLine) {
            if (isset($rootLine['is_siteroot']) && ($rootLine['is_siteroot'] === 1)) {
                return $rootLine['uid'];
            }
        }

        return 0;
    }

    /**
     * Returns the UIDs of all pages below the given page, whatever their type.
     *
     * Unlike getPageIdsRecursive() this walk is not the one of an indexer. It
     * follows every page type and has no depth limit, because it has to find
     * what DataHandler deletes together with a page: hidden pages, recycler and
     * backend user section pages and everything below them.
     *
     * For the tree of a site, $forSiteTree leaves out what an indexer of that site cannot
     * reach, or what a move does not decide:
     *
     * - A site root below the page together with everything below it, which is the part
     *   of the tree that belongs to its own site. This is decided from the pages themselves
     *   and not from their rootlines, which can still be cached for a tree that was just moved.
     * - The translations of pages, which carry the parent of the page they translate. The page
     *   walk of a service with a page selection covers the default language only, and a move does
     *   not decide the translations of a page.
     * - What lies below a page that is hidden, or not yet or no longer published, and extends
     *   that state to its subpages, because it cannot be reached in the frontend. Such a page
     *   itself is part of the answer.
     *
     * @param int  $pageId      The UID of the page whose descendants are wanted
     * @param bool $forSiteTree Whether the walk is the one of the tree of a site
     *
     * @return int[] The UIDs of the descendants, without the page itself
     *
     * @throws Exception If a database error occurs during the query
     */
    #[Override]
    public function getDescendantPageIds(
        int $pageId,
        bool $forSiteTree = false,
    ): array {
        $descendants = [];
        $level       = [$pageId];
        $now         = PublicationWindow::now();

        // The page itself can hide everything below it
        if (
            $forSiteTree
            && $this->hidesItsSubpages($this->getPageRecord('pages', $pageId), $now)
        ) {
            return [];
        }

        while ($level !== []) {
            $nextLevel = [];

            // A wide level is asked for in blocks, a single IN list is limited by the database
            foreach (array_chunk($level, Constants::IN_LIST_BLOCK_SIZE) as $chunk) {
                $queryBuilder = $this->connectionPool
                    ->getQueryBuilderForTable('pages');

                $queryBuilder
                    ->getRestrictions()
                    ->removeByType(HiddenRestriction::class)
                    ->removeByType(StartTimeRestriction::class)
                    ->removeByType(EndTimeRestriction::class)
                    ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));

                $queryBuilder
                    ->select('uid', 'is_siteroot', 'hidden', 'starttime', 'endtime', 'extendToSubpages')
                    ->from('pages')
                    ->where(
                        $queryBuilder->expr()->in(
                            'pid',
                            $queryBuilder->createNamedParameter($chunk, ArrayParameterType::INTEGER)
                        )
                    );

                if ($forSiteTree) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq('sys_language_uid', 0)
                    );
                }

                $rows = $queryBuilder
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($rows as $row) {
                    $uid = (int) $row['uid'];

                    // A site root keeps its own site, so it and its tree are not part of this one
                    if (
                        $forSiteTree
                        && ((int) $row['is_siteroot'] === 1)
                    ) {
                        continue;
                    }

                    // A page has one parent, so a ring can only lead back to the page the walk
                    // started from, and that page is not part of the answer
                    if ($uid === $pageId) {
                        continue;
                    }

                    $descendants[$uid] = $uid;

                    // A page that extends its state hides its subpages with it
                    if (
                        $forSiteTree
                        && $this->hidesItsSubpages($row, $now)
                    ) {
                        continue;
                    }

                    $nextLevel[] = $uid;
                }
            }

            $level = $nextLevel;
        }

        return array_values($descendants);
    }

    /**
     * Tells whether a page is hidden, or not yet or no longer published, and extends that state
     * to its subpages, so that nothing below it can be reached in the frontend.
     *
     * @param array<string, int|string|null> $page The page record, with the fields hidden, starttime,
     *                                             endtime and extendToSubpages
     * @param int                            $now  The current time
     *
     * @return bool TRUE if the page is hidden or outside of its publication window and extends that state
     */
    private function hidesItsSubpages(
        array $page,
        int $now,
    ): bool {
        if (((int) ($page['extendToSubpages'] ?? 0)) !== 1) {
            return false;
        }

        return PublicationWindow::isHiddenOrOutside($page, $now);
    }
}
