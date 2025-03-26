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
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The domain model page repository.
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
     * Recursively fetches all pages with given IDs. Includes the list of given page IDs.
     *
     * @param int[]       $pageIds
     * @param int<0, max> $depth
     *
     * @return int[]
     *
     * @throws Exception
     */
    public function getPageIdsRecursive(array $pageIds, int $depth): array
    {
        if ($pageIds === []) {
            return [];
        }

        if ($depth === 0) {
            return $pageIds;
        }

        $recursivePageIds = [$pageIds];

        foreach ($pageIds as $pageId) {
            $recursivePageIds[] = $this->getSubPageIdsRecursive($pageId, $depth);
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
     * @param int         $id    The UID of the page
     * @param int<0, max> $depth
     *
     * @return int[] The list of descendant page
     *
     * @throws Exception
     */
    public function getSubPageIdsRecursive(int $id, int $depth): array
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
                $pageIds[] = $this->getSubPageIdsRecursive($subPageRow['uid'], $depth - 1);
            }
        }

        return array_merge(...$pageIds);
    }
}
