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
     * Updates records of one table in the indexing queue in one pass, by removing and re-adding them.
     *
     * The indexing services are resolved once, every service accepts or rejects the records
     * of the list with one lookup per block of records, and the documents of the records that
     * no consulted service of a search index accepts are removed from that index in blocks.
     *
     * When none of the indexing services consulted for a record that write to one search index
     * accepts it any more, its document is also removed from that index, and other indexes are
     * decided separately. The consulted services are the ones of the site of the records and, for
     * files, every file indexing service. The document is kept when a service that was not
     * consulted writes to the same index. A failure while removing documents is logged when a
     * logger is available and does not throw. A record that was just created normally has no
     * document, so the caller passes FALSE for it and no removal is attempted. A record that
     * moved to another site is decided over the services of both sites, so that the document
     * is only kept when a service of one of them accepts the record or when a service of a third
     * site writes to the same index.
     *
     * @param int      $rootPageId              The root page UID to filter indexing services by page tree
     * @param string   $tableName               The name of the database table containing the records
     * @param int[]    $recordUids              The unique identifiers of the records to update in the queue
     * @param bool     $removeRejectedDocuments Whether to remove the documents of records that no indexer accepts
     * @param int|null $previousRootPageId      The root page UID of the former site of moved records
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue operations
     */
    public function updateRecordsInQueue(
        int $rootPageId,
        string $tableName,
        array $recordUids,
        bool $removeRejectedDocuments = true,
        ?int $previousRootPageId = null,
    ): void;

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
     * @param int        $pageId                    The unique identifier of the page containing the content elements
     * @param bool       $removePageContentElements Whether to remove elements from queue and index (true) or add them to the queue (false)
     * @param int[]|null $contentElementUids        The UIDs of the content elements, or NULL to look them up on the page
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the queue or index operations
     */
    public function processContentElementsOfPage(
        int $pageId,
        bool $removePageContentElements,
        ?array $contentElementUids = null,
    ): void;

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
     * Removes several records of one table from the indexing queue and from the
     * search index of every indexing service of the page tree of the given root page.
     *
     * The indexing services are resolved once for all records.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The database table name of the records
     * @param int[]  $recordUids The unique identifiers of the records
     *
     * @return void
     */
    public function removeRecords(
        int $rootPageId,
        string $tableName,
        array $recordUids,
    ): void;

    /**
     * Resolves the indexing services of the page tree of the given root page together
     * with their indexers, as the targets of a later removal.
     *
     * @param int    $rootPageId The root page UID to filter indexing services by page tree
     * @param string $tableName  The database table name of the records
     *
     * @return array<int, array{IndexingService, IndexerInterface}> The indexing services with their indexers
     */
    public function resolveIndexerTargets(
        int $rootPageId,
        string $tableName,
    ): array;

    /**
     * Removes several records of one table from the indexing queue and from the search
     * index of the given targets.
     *
     * @param array<int, array{IndexingService, IndexerInterface}> $targets    The indexing services with their indexers, see resolveIndexerTargets()
     * @param string                                               $tableName  The database table name of the records
     * @param int[]                                                $recordUids The unique identifiers of the records
     *
     * @return void
     */
    public function removeRecordsFromTargets(
        array $targets,
        string $tableName,
        array $recordUids,
    ): void;
}
