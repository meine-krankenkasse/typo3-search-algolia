<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

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
readonly class PageRepository
{
    /**
     * TYPO3 database connection pool for direct database operations.
     *
     * This property provides access to database connections for performing
     * optimized database queries on pages. It is used to create query builders
     * for retrieving pages and navigating page hierarchies efficiently.
     *
     * @var ConnectionPool
     */
    private ConnectionPool $connectionPool;

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
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
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
            $id = (int) abs($id);
        }

        $pageIds = [[]];

        if (($id > 0) && ($depth > 0)) {
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

            $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter(
                            $id,
                            Connection::PARAM_INT
                        )
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        0
                    ),
                    // Exclude some page types, see \TYPO3\CMS\Core\Domain\Repository\PageRepository::getSubpagesRecursive
                    $queryBuilder->expr()->notIn(
                        'doktype',
                        [
                            \TYPO3\CMS\Core\Domain\Repository\PageRepository::DOKTYPE_RECYCLER,
                            \TYPO3\CMS\Core\Domain\Repository\PageRepository::DOKTYPE_BE_USER_SECTION,
                        ]
                    )
                )
                ->orderBy('uid');

            $statement = $queryBuilder
                ->executeQuery();

            while ($subPageRow = $statement->fetchAssociative()) {
                $pageIds[] = [$subPageRow['uid']];
                $pageIds[] = $this->getSubPageIdsRecursive($subPageRow['uid'], $depth - 1, $excludeHiddenPages);
            }
        }

        return array_merge(...$pageIds);
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
}
