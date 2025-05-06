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
 * The page repository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class PageRepository
{
    /**
     * @var ConnectionPool
     */
    private ConnectionPool $connectionPool;

    /**
     * Constructor.
     *
     * @param ConnectionPool $connectionPool
     */
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Recursively fetches all pages with given IDs.
     *
     * @param int[]       $pageIds              A list of page IDs for which the subpages will be recursively determined
     * @param int<0, max> $depth                The recursive iteration depth
     * @param bool        $includeAncestorPages Set to TRUE to include the given page IDs in the result
     * @param bool        $excludeHiddenPages   Set to TRUE to exclude hidden subpages from the result
     *
     * @return int[]
     *
     * @throws Exception
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
     * Recursively fetch all descendants of a given page. Excludes the current page ID and also pages of
     * page type DOKTYPE_RECYCLER and DOKTYPE_BE_USER_SECTION.
     *
     * This method is a duplication and modification of \TYPO3\CMS\Core\Database\QueryGenerator as this has
     * been deprecated/removed.
     *
     * @param int         $id                 The UID of the page
     * @param int<0, max> $depth              The recursive iteration depth
     * @param bool        $excludeHiddenPages Set to TRUE to exclude hidden subpages from the result
     *
     * @return int[] The list of descendant page
     *
     * @throws Exception
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
     * Returns the UID of the root page to which this page belongs. Returns 0 if the
     * root page ID could not be determined.
     *
     * @param int $pageId The page ID to be used to determine the root page ID
     *
     * @return int The page ID of the root page
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
