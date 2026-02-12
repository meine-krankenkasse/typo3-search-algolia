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
 * Interface for accessing content elements stored in the database.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface ContentRepositoryInterface
{
    /**
     * Retrieves the header and parent page UID of a content element.
     *
     * @param int $uid The unique identifier of the content element record
     *
     * @return array<string, int|string> An associative array containing 'header' and 'page_uid'
     */
    public function findInfo(int $uid): array;

    /**
     * Retrieves all content elements from a specific page with optional filtering.
     *
     * @param int      $pageId              The UID of the page containing the content elements
     * @param string[] $columns             Array of column names to retrieve from each record
     * @param string[] $contentElementTypes Optional list of content element types (CType) to filter by
     *
     * @return array<int, array<string, mixed>> Array of content element records, each as an associative array
     *
     * @throws Exception If a database error occurs during the query
     */
    public function findAllByPid(int $pageId, array $columns, array $contentElementTypes = []): array;
}
