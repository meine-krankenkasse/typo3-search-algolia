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

/**
 * Interface for accessing and navigating TYPO3 page structures.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface PageRepositoryInterface
{
    /**
     * Retrieves the title of a specific page.
     *
     * @param int $uid The unique identifier of the page record
     *
     * @return string The title of the page, or an empty string if the page doesn't exist
     */
    public function findTitle(int $uid): string;

    /**
     * Retrieves a database record for a given table and record UID.
     *
     * @param string $tableName       The name of the database table to fetch the record from
     * @param int    $recordUid       The UID of the record to retrieve
     * @param string $fields          The fields to select in the query, defaults to '*'
     * @param bool   $useDeleteClause Whether to include a clause for filtering deleted records, defaults to true
     *
     * @return array<string, int|string|null> An associative array containing the record data, or an empty array if the record is not found
     */
    public function getPageRecord(
        string $tableName,
        int $recordUid,
        string $fields = '*',
        bool $useDeleteClause = true,
    ): array;

    /**
     * Recursively fetches all pages starting from the given page IDs.
     *
     * @param int[]       $pageIds              A list of page IDs for which the subpages will be recursively determined
     * @param int<0, max> $depth                The recursive iteration depth
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
    ): array;

    /**
     * Determines the root page (site root) for any page in the TYPO3 page tree.
     *
     * @param int $pageId The page ID to find the root page for
     *
     * @return int The UID of the root page, or 0 if no root page could be determined
     */
    public function getRootPageId(int $pageId): int;
}
