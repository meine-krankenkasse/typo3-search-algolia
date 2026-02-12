<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\DataHandling;

use Doctrine\DBAL\Exception;
use Generator;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;

/**
 * Interface for the core handler for database record operations in the search indexing process.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface RecordHandlerInterface
{
    /**
     * Creates a generator that yields indexing service and indexer instance pairs.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The name of the database table to find indexing services for
     *
     * @return Generator A generator yielding pairs of IndexingService => IndexerInterface
     */
    public function createIndexerGenerator(int $rootPageId, string $tableName): Generator;

    /**
     * Updates a record in the indexing queue by removing and re-adding it.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The name of the database table containing the record
     * @param int    $recordUid  The unique identifier of the record to update in the queue
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue operations
     */
    public function updateRecordInQueue(int $rootPageId, string $tableName, int $recordUid): void;

    /**
     * Updates a page in the indexing queue when one of its content elements changes.
     *
     * @param int $rootPageId The root page UID to filter indexing services by page tree
     * @param int $pageId     The unique identifier of the page containing the modified content element
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue operations
     */
    public function processPageOfContentElement(int $rootPageId, int $pageId): void;

    /**
     * Manages the indexing status of all content elements on a specific page.
     *
     * @param int  $pageId                    The unique identifier of the page containing the content elements
     * @param bool $removePageContentElements Whether to remove elements from queue and index (true) or add them to the queue (false)
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue or index operations
     */
    public function processContentElementsOfPage(int $pageId, bool $removePageContentElements): void;

    /**
     * Retrieves the root page ID for a given record.
     *
     * @param array<string, int|string|null> $pageRecord The record data used for determining the page ID
     * @param string                         $tableName  The name of the table that the record belongs to
     * @param int                            $recordUid  The unique identifier of the record
     *
     * @return int The resolved root page ID
     */
    public function getRecordRootPageId(array $pageRecord, string $tableName, int $recordUid): int;

    /**
     * Removes a single record from the indexing queue and optionally from the search index.
     *
     * @param IndexingService  $indexingService   The indexing service configuration to use
     * @param IndexerInterface $indexerInstance   The indexer instance for the record's type
     * @param string           $tableName         The database table name of the record
     * @param int              $recordUid         The unique identifier of the record
     * @param bool             $isRemoveFromIndex Whether to also remove the record from the search engine index
     *
     * @return void
     */
    public function deleteRecord(
        IndexingService $indexingService,
        IndexerInterface $indexerInstance,
        string $tableName,
        int $recordUid,
        bool $isRemoveFromIndex,
    ): void;

    /**
     * Removes multiple records from the indexing queue and optionally from the search index.
     *
     * @param IndexingService  $indexingService   The indexing service configuration to use
     * @param IndexerInterface $indexerInstance   The indexer instance for the records' type
     * @param string           $tableName         The database table name of the records
     * @param int[]            $recordUids        Array of unique identifiers for the records to delete
     * @param bool             $isRemoveFromIndex Whether to also remove the records from the search engine index
     *
     * @return void
     */
    public function deleteRecords(
        IndexingService $indexingService,
        IndexerInterface $indexerInstance,
        string $tableName,
        array $recordUids,
        bool $isRemoveFromIndex,
    ): void;
}
