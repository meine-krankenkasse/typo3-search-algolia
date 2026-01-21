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

use function is_array;

/**
 * Repository for accessing content elements stored in the database.
 *
 * This repository provides methods for retrieving content elements from the TYPO3
 * database. It offers specialized finder methods for:
 * - Finding all content elements on a specific page
 * - Filtering content elements by type (CType)
 *
 * The repository uses direct database queries via TYPO3's ConnectionPool for
 * optimal performance when retrieving content element data.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class ContentRepository
{
    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections for different tables.
     *
     * @param ConnectionPool $connectionPool The TYPO3 database connection pool
     */
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Retrieves the header and parent page UID of a content element.
     *
     * This method fetches specific metadata for a content element (tt_content record)
     * by its UID. It returns the content element's header text and the UID of the
     * page it is located on.
     *
     * This information is used for displaying content element details in the
     * backend module's indexing queue statistics.
     *
     * @param int $uid The unique identifier of the content element record
     *
     * @return array<string, int|string> An associative array containing 'header' and 'page_uid'
     */
    public function findInfo(int $uid): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tt_content');

        $content = $queryBuilder
            ->select(
                'c.header',
                'c.pid AS page_uid'
            )
            ->from('tt_content', 'c')
            ->where(
                $queryBuilder->expr()->eq(
                    'c.uid',
                    $queryBuilder->createNamedParameter(
                        $uid,
                        Connection::PARAM_INT
                    )
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($content)) {
            return [];
        }

        return [
            'header'   => (string) $content['header'],
            'page_uid' => (int) $content['page_uid'],
        ];
    }

    /**
     * Retrieves all content elements from a specific page with optional filtering.
     *
     * This method fetches content elements (tt_content records) that are located
     * on the specified page. It allows:
     * - Selecting specific columns to retrieve (for performance optimization)
     * - Filtering by content element types (CType)
     *
     * The method is primarily used by indexers to retrieve content elements
     * that need to be indexed, or by event listeners that need to process
     * content elements for inclusion in page documents.
     *
     * @param int      $pageId              The UID of the page containing the content elements
     * @param string[] $columns             Array of column names to retrieve from each record
     * @param string[] $contentElementTypes Optional list of content element types (CType) to filter by
     *
     * @return array<int, array<string, mixed>> Array of content element records, each as an associative array
     *
     * @throws Exception If a database error occurs during the query
     */
    public function findAllByPid(int $pageId, array $columns, array $contentElementTypes = []): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tt_content');

        $constraints = [
            $queryBuilder->expr()->eq(
                'pid',
                $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
            ),
        ];

        if ($contentElementTypes !== []) {
            // Filter by CType
            $constraints[] = $queryBuilder->expr()->in(
                'CType',
                $queryBuilder->quoteArrayBasedValueListToStringList($contentElementTypes)
            );
        }

        return $queryBuilder
            ->select(...$columns)
            ->from('tt_content')
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
