<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use Override;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Indexer for TYPO3 content elements (tt_content).
 *
 * This indexer is responsible for retrieving and processing content elements
 * from the TYPO3 database for indexing in search engines. It handles:
 *
 * - Filtering content elements by type (CType)
 * - Respecting page constraints from the indexing service configuration
 * - Creating searchable documents from content element records
 *
 * Content elements are the building blocks of TYPO3 pages and contain
 * the actual content that users are typically searching for.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ContentIndexer extends AbstractIndexer
{
    /**
     * The database table name for content elements.
     *
     * This constant defines the TYPO3 database table that stores content elements.
     * It is used throughout the indexer to identify which table to query.
     */
    public const string TABLE = 'tt_content';

    /**
     * Returns the database table name that this indexer is responsible for.
     *
     * This method implements the abstract method from AbstractIndexer and
     * returns the tt_content table name, which is where TYPO3 stores all
     * content elements.
     *
     * @return string The database table name (tt_content)
     */
    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }

    /**
     * Returns additional query constraints specific to content elements.
     *
     * This method adds filtering by content element types (CType) if specified
     * in the indexing service configuration. This allows administrators to
     * limit which types of content elements are indexed (e.g., only text elements,
     * only certain plugins, etc.).
     *
     * @param QueryBuilder $queryBuilder The query builder to use for creating expressions
     *
     * @return string[] Array of SQL constraint expressions
     */
    #[Override]
    protected function getAdditionalQueryConstraints(QueryBuilder $queryBuilder): array
    {
        $constraints = [];

        // Get content element types from indexing service configuration
        $contentElementTypes = GeneralUtility::trimExplode(
            ',',
            $this->indexingService?->getContentElementTypes() ?? '',
            true
        );

        if ($contentElementTypes !== []) {
            // Filter by CType
            $constraints[] = $queryBuilder->expr()->in(
                'CType',
                $queryBuilder->quoteArrayBasedValueListToStringList($contentElementTypes)
            );
        }

        return $constraints;
    }
}
