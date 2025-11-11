<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use Override;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Indexer for TYPO3 pages.
 *
 * This indexer is responsible for retrieving and processing pages
 * from the TYPO3 database for indexing in search engines. It handles:
 * - Filtering pages by type (doktype)
 * - Excluding pages marked as "no_search"
 * - Optionally including content elements from pages
 * - Creating searchable documents from page records
 *
 * Pages are the fundamental structure of a TYPO3 website and contain
 * important metadata like title, description, and navigation information.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class PageIndexer extends AbstractIndexer
{
    /**
     * The database table name for pages.
     *
     * This constant defines the TYPO3 database table that stores pages.
     * It is used throughout the indexer to identify which table to query.
     */
    public const string TABLE = 'pages';

    /**
     * Returns the database table name that this indexer is responsible for.
     *
     * This method implements the abstract method from AbstractIndexer and
     * returns the pages table name, which is where TYPO3 stores all
     * page records.
     *
     * @return string The database table name (pages)
     */
    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }

    /**
     * Determines whether content elements should be included in page indexing.
     *
     * This method checks the indexing service configuration to determine if
     * content elements from the page should be included in the page's index entry.
     * When enabled, content from the page will be stored directly in the page's
     * document, which can improve search relevance but may increase document size.
     *
     * @return bool True if content elements should be included, false otherwise
     */
    public function isIncludeContentElements(): bool
    {
        return ($this->indexingService instanceof IndexingService)
            && $this->indexingService->isIncludeContentElements();
    }

    /**
     * Returns additional query constraints specific to pages.
     *
     * This method adds filtering by:
     * 1. Excluding pages marked with "no_search" flag
     * 2. Filtering by page types (doktype) if specified in the indexing service configuration
     *
     * These constraints ensure that only appropriate pages are indexed, respecting
     * both the page settings and the indexing configuration.
     *
     * @param QueryBuilder $queryBuilder The query builder to use for creating expressions
     *
     * @return string[] Array of SQL constraint expressions
     */
    #[Override]
    protected function getAdditionalQueryConstraints(QueryBuilder $queryBuilder): array
    {
        $constraints = array_merge(
            parent::getAdditionalQueryConstraints($queryBuilder),
            [
                // Include only pages which are not explicitly excluded from search
                $queryBuilder->expr()->eq(
                    'no_search',
                    0
                ),
            ]
        );

        // Get page types from indexing service configuration
        $pageTypes = GeneralUtility::intExplode(
            ',',
            $this->indexingService?->getPagesDoktype() ?? '',
            true
        );

        if ($pageTypes !== []) {
            // Filter by page type
            $constraints[] = $queryBuilder->expr()->in(
                'doktype',
                $queryBuilder->quoteArrayBasedValueListToIntegerList($pageTypes)
            );
        }

        return $constraints;
    }
}
