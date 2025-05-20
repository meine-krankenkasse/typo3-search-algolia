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

/**
 * Indexer for TYPO3 news records.
 *
 * This indexer is responsible for retrieving and processing news records
 * from the TYPO3 database for indexing in search engines. It handles:
 * - Retrieving news records from the news extension
 * - Creating searchable documents from news records
 * - Respecting page constraints from the indexing service configuration
 *
 * News records are important content elements that often contain
 * time-sensitive information that users may want to find through search.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class NewsIndexer extends AbstractIndexer
{
    /**
     * The database table name for news records.
     *
     * This constant defines the TYPO3 database table that stores news records
     * from the tx_news extension. It is used throughout the indexer to identify
     * which table to query.
     */
    public const string TABLE = 'tx_news_domain_model_news';

    /**
     * Returns the database table name that this indexer is responsible for.
     *
     * This method implements the abstract method from AbstractIndexer and
     * returns the tx_news_domain_model_news table name, which is where
     * the tx_news extension stores all news records.
     *
     * @return string The database table name (tx_news_domain_model_news)
     */
    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }
}
