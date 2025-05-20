<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use RuntimeException;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Interface for all indexers in the search system.
 *
 * Indexers are responsible for retrieving, processing, and sending content
 * to search engines for indexing. Each indexer handles a specific type of
 * content (pages, content elements, files, etc.) and knows how to:
 * - Retrieve records from the database
 * - Transform records into searchable documents
 * - Manage indexing queues for scheduled processing
 * - Apply filtering based on page constraints and other criteria
 *
 * Implementations of this interface should provide specific logic for
 * different content types while leveraging the common functionality
 * provided by AbstractIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @api
 */
interface IndexerInterface extends SingletonInterface
{
    /**
     * Returns the database table name that this indexer is responsible for.
     *
     * This method identifies which database table the indexer operates on.
     * Each indexer is typically responsible for a single table (e.g., pages,
     * tt_content, sys_file_metadata, etc.).
     *
     * @return string The database table name
     */
    public function getTable(): string;

    /**
     * Creates a new instance with the specified indexing service configuration.
     *
     * This method implements the immutable pattern, returning a new instance
     * with the provided indexing service configuration without modifying the
     * original instance. The indexing service contains settings like which
     * search engine to use, which pages to index, etc.
     *
     * @param IndexingService $indexingService The indexing service configuration to use
     *
     * @return IndexerInterface A new instance with the specified indexing service
     */
    public function withIndexingService(IndexingService $indexingService): IndexerInterface;

    /**
     * Creates a new instance with the specified hidden pages exclusion setting.
     *
     * This method implements the immutable pattern, returning a new instance
     * with the provided setting for excluding hidden pages without modifying
     * the original instance. When set to true, pages marked as hidden in the
     * TYPO3 backend will be excluded from indexing operations.
     *
     * @param bool $excludeHiddenPages Whether to exclude hidden pages from indexing
     *
     * @return IndexerInterface A new instance with the specified hidden pages exclusion setting
     */
    public function withExcludeHiddenPages(bool $excludeHiddenPages): IndexerInterface;

    /**
     * Indexes a single record in the search engine.
     *
     * This method processes a record and sends it to the search engine for indexing.
     * It handles the complete indexing process:
     * 1. Creating a search engine instance based on the indexing service configuration
     * 2. Building a document from the record
     * 3. Opening the appropriate index in the search engine
     * 4. Adding or updating the document in the search engine
     * 5. Committing the changes and closing the index
     *
     * @param IndexingService      $indexingService The indexing service configuration to use
     * @param array<string, mixed> $record          The record data to be indexed
     *
     * @return bool True if indexing was successful, false otherwise
     */
    public function indexRecord(IndexingService $indexingService, array $record): bool;

    /**
     * Removes a single record from the indexing queue.
     *
     * This method deletes the queue item for a specific record, effectively
     * removing it from the list of records to be indexed. This is useful when
     * a record has been processed or should no longer be considered for indexing.
     *
     * @param int $recordUid The unique identifier of the record to remove from the queue
     *
     * @return IndexerInterface The current indexer instance for method chaining
     *
     * @throws RuntimeException If no indexing service is set
     */
    public function dequeueOne(int $recordUid): IndexerInterface;

    /**
     * Removes multiple records from the indexing queue.
     *
     * This method deletes the queue items for a set of records, effectively
     * removing them from the list of records to be indexed. This is useful when
     * multiple records have been processed or should no longer be considered for indexing.
     *
     * @param int[] $recordUids An array of record UIDs to remove from the queue
     *
     * @return IndexerInterface The current indexer instance for method chaining
     *
     * @throws RuntimeException If no indexing service is set
     */
    public function dequeueMultiple(array $recordUids): IndexerInterface;

    /**
     * Removes all records of this indexer's type from the indexing queue.
     *
     * This method deletes all queue items associated with the current
     * indexing service and table, effectively clearing the queue for
     * this specific indexer configuration. This is useful when you want
     * to start fresh with a new indexing run.
     *
     * @return IndexerInterface The current indexer instance for method chaining
     *
     * @throws RuntimeException If no indexing service is set
     */
    public function dequeueAll(): IndexerInterface;

    /**
     * Adds a single record to the indexing queue.
     *
     * This method creates a queue item for a specific record, marking it
     * for indexing in the next indexing run. This is useful when a record
     * has been created or updated and needs to be indexed.
     *
     * @param int $recordUid The unique identifier of the record to add to the queue
     *
     * @return int The number of records successfully added to the queue (0 or 1)
     *
     * @throws RuntimeException If no indexing service is set or if there's an error adding to the queue
     */
    public function enqueueOne(int $recordUid): int;

    /**
     * Adds multiple records to the indexing queue.
     *
     * This method creates queue items for a set of records, marking them
     * for indexing in the next indexing run. This is useful when multiple
     * records have been created or updated and need to be indexed.
     *
     * @param int[] $recordUids An array of record UIDs to add to the queue
     *
     * @return int The number of records successfully added to the queue
     *
     * @throws RuntimeException If no indexing service is set or if there's an error adding to the queue
     */
    public function enqueueMultiple(array $recordUids): int;

    /**
     * Adds all eligible records of this indexer's type to the indexing queue.
     *
     * This method creates queue items for all records that match the indexing
     * criteria defined by the current indexing service configuration. This is
     * useful for initial indexing or for reindexing all content of a certain type.
     *
     * @return int The number of records successfully added to the queue
     *
     * @throws RuntimeException If no indexing service is set or if there's an error adding to the queue
     */
    public function enqueueAll(): int;
}
