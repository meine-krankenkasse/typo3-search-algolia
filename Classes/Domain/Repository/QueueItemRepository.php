<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

use function count;

/**
 * Repository for managing indexing queue items.
 *
 * This repository provides methods for working with the indexing queue:
 * - Retrieving queue items for processing by the indexing system
 * - Adding new items to the queue when content is created or modified
 * - Removing items from the queue after processing or when content is deleted
 * - Generating statistics about the current state of the queue
 *
 * The repository uses both Extbase's persistence layer for standard operations
 * and direct database queries via TYPO3's ConnectionPool for performance-critical
 * operations like bulk inserts and statistics gathering.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @extends Repository<QueueItem>
 */
class QueueItemRepository extends Repository
{
    private const string TABLE_NAME = 'tx_typo3searchalgolia_domain_model_queueitem';

    /**
     * Initializes the repository with required dependencies.
     *
     * This constructor injects the TYPO3 connection pool that is used for direct
     * database operations throughout the repository. The connection pool allows
     * for optimized database queries that bypass Extbase's persistence layer
     * when needed for performance reasons, particularly for bulk operations
     * and statistics gathering.
     *
     * @param ConnectionPool    $connectionPool    The TYPO3 database connection pool
     * @param ContentRepository $contentRepository The content repository
     * @param PageRepository    $pageRepository    The page repository
     * @param FileRepository    $fileRepository    The file repository
     */
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ContentRepository $contentRepository,
        private readonly PageRepository $pageRepository,
        private readonly FileRepository $fileRepository,
    ) {
        parent::__construct();
    }

    /**
     * Initializes the repository with custom query settings.
     *
     * This method configures the repository's default query settings to:
     * - Respect enable fields (only return visible records)
     * - Ignore storage page restrictions (find records regardless of their location)
     *
     * These settings ensure that queue items are only processed if they are
     * active (not hidden) but can be found regardless of which page they are
     * stored on, which is important for system-wide queue processing.
     *
     * @return void
     */
    public function initializeObject(): void
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings
            ->setIgnoreEnableFields(false)
            ->setRespectStoragePage(false);

        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Retrieves a limited number of queue items for processing.
     *
     * This method fetches queue items from the database, limiting the result
     * to the specified number of items. The items are ordered by their 'changed'
     * timestamp in descending order, ensuring that the most recently changed
     * records are processed first. This prioritization helps keep the search
     * index up-to-date with the latest content changes.
     *
     * This method is primarily used by the IndexQueueWorkerCommand to retrieve
     * batches of queue items for processing during scheduled indexing runs.
     *
     * @param int $limit The maximum number of queue items to retrieve
     *
     * @return QueryResultInterface<QueueItem> Collection of queue items ready for processing
     */
    public function findAllLimited(int $limit): QueryResultInterface
    {
        return $this->createQuery()
            ->setLimit($limit)
            ->setOrderings(
                [
                    'changed' => QueryInterface::ORDER_DESCENDING,
                ]
            )
            ->execute();
    }

    /**
     * Generates statistics about the current state of the indexing queue.
     *
     * This method performs a direct database query to count the number of queue
     * items grouped by table name. It provides an overview of how many items of
     * each content type are currently waiting to be indexed, which is useful for:
     * - Displaying queue status information in the backend module
     * - Monitoring the indexing workload
     * - Identifying potential bottlenecks in the indexing process
     *
     * The method uses a direct database query for optimal performance when
     * dealing with potentially large numbers of queue items.
     *
     * @return array<int, array<string, int|string|array<mixed>>> Array of statistics records, each containing 'table_name', 'count' and 'items' values
     *
     * @throws Exception If a database error occurs during the query
     */
    public function getStatistics(): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE_NAME);

        $statistics = $queryBuilder
            ->select('table_name')
            ->addSelectLiteral('COUNT(*) AS count')
            ->from(self::TABLE_NAME)
            ->groupBy('table_name')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($statistics as &$statistic) {
            $statistic['items'] = $this->findAllByTableName((string) $statistic['table_name']);
        }

        return $statistics;
    }

    /**
     * Retrieves all queue items for a specific database table, enriched with details.
     *
     * This method fetches all records from the indexing queue that belong to the
     * specified table name. Depending on the table type, it automatically enriches
     * the results with additional information from specialized repositories:
     * - 'sys_file_metadata': Adds file info (name, path, type) and content element usages
     * - 'pages': Adds the page title
     * - 'tt_content': Adds content element info (header and parent page UID)
     *
     * The results are ordered by the record UID in ascending order to provide
     * a consistent view in the backend module's statistics.
     *
     * @param string $tableName The database table name to filter queue items by
     *
     * @return array<int, array<string, mixed>> A list of queue item records with additional metadata
     *
     * @throws Exception If a database error occurs during the query
     */
    public function findAllByTableName(string $tableName): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE_NAME);

        $items = $queryBuilder
            ->select('record_uid')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'table_name',
                    $queryBuilder->createNamedParameter($tableName)
                )
            )
            ->orderBy('record_uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($tableName === 'sys_file_metadata') {
            foreach ($items as &$item) {
                $item['file_info'] = $this->fileRepository->findInfo((int) $item['record_uid']);
                $item['usages']    = $this->fileRepository->findUsages((int) $item['record_uid']);
            }
        }

        if ($tableName === 'pages') {
            foreach ($items as &$item) {
                $item['page_title'] = $this->pageRepository->findTitle((int) $item['record_uid']);
            }
        }

        if ($tableName === 'tt_content') {
            foreach ($items as &$item) {
                $item['content_info'] = $this->contentRepository->findInfo((int) $item['record_uid']);
            }
        }

        return $items;
    }

    /**
     * Efficiently adds multiple records to the indexing queue in a single operation.
     *
     * This method performs a bulk insert operation to add multiple queue items
     * to the database at once. It's optimized for performance when adding large
     * numbers of items by:
     * - Using direct database operations instead of Extbase's persistence layer
     * - Chunking the records into manageable batches (1000 records per query)
     * - Minimizing the number of database transactions
     *
     * This approach is significantly faster than adding records individually,
     * which is crucial when refreshing the queue for large content collections
     * or during initial indexing operations.
     *
     * @param array<int, array<string, int|string>> $records array of queue item records, each containing table_name, record_uid, service_uid, etc
     *
     * @return int The total number of records successfully added to the queue
     */
    public function bulkInsert(array $records): int
    {
        $itemCount = count($records);

        if ($itemCount <= 0) {
            return 0;
        }

        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE_NAME);

        // Avoid errors caused by too many records by dividing them into blocks.
        $recordsChunks = array_chunk($records, 1000);
        $columns       = array_keys(reset($records));

        foreach ($recordsChunks as $recordsChunk) {
            $connection
                ->bulkInsert(
                    self::TABLE_NAME,
                    $recordsChunk,
                    $columns
                );
        }

        return $itemCount;
    }

    /**
     * Adds a single record to the indexing queue.
     *
     * This method inserts a single queue item record into the database using
     * a direct database operation. Unlike bulkInsert(), this method is designed
     * for adding individual records when only one item needs to be queued.
     *
     * While not as efficient as bulkInsert() for large numbers of records,
     * this method is simpler and has less overhead when only a single record
     * needs to be added to the queue, such as when a specific content item
     * is updated and needs to be re-indexed.
     *
     * @param array<string, int|string> $record queue item record containing table_name, record_uid, service_uid, etc
     *
     * @return int The number of affected rows (1 if successful, 0 if failed)
     */
    public function insert(array $record): int
    {
        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE_NAME);

        return $connection->insert(self::TABLE_NAME, $record);
    }

    /**
     * Removes all queue items associated with a specific indexing service.
     *
     * This method deletes all queue items that were created by a particular
     * indexing service configuration. It's typically used when:
     * - An indexing service configuration is deleted or disabled
     * - The queue needs to be refreshed for a specific indexing service
     * - All items of a certain type need to be removed from the queue
     *
     * The method uses a direct database operation with transaction handling
     * to ensure that the deletion is performed atomically, preventing partial
     * deletions if an error occurs during the operation.
     *
     * @param IndexingService $indexingService The indexing service whose queue items should be removed
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the deletion process
     */
    public function deleteByIndexingService(IndexingService $indexingService): void
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE_NAME);

        $queryBuilder
            ->delete(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'service_uid',
                    $queryBuilder->createNamedParameter(
                        $indexingService->getUid(),
                        Connection::PARAM_INT
                    )
                )
            );

        try {
            $queryBuilder->getConnection()->beginTransaction();
            $queryBuilder->executeStatement();
            $queryBuilder->getConnection()->commit();
        } catch (Exception $e) {
            $queryBuilder->getConnection()->rollBack();
            throw $e;
        }
    }

    /**
     * Removes specific queue items based on table name, record UIDs, and optionally service UID.
     *
     * This method provides flexible deletion of queue items with various filtering options:
     * - Always filters by table name to target specific content types
     * - Optionally filters by record UIDs when specific records need to be removed
     * - Optionally filters by service UID when only items from a specific indexing service should be removed
     *
     * This flexibility allows for targeted queue management operations such as:
     * - Removing all items of a specific content type (e.g., all pages)
     * - Removing specific records that have been deleted from the system
     * - Removing items for specific records from a particular indexing service
     *
     * The method uses a direct database operation with transaction handling
     * to ensure that the deletion is performed atomically, preventing partial
     * deletions if an error occurs during the operation.
     *
     * @param string $tableName  The database table name to filter queue items by
     * @param int[]  $recordUids Optional array of record UIDs to remove (if empty, all records of the table are removed)
     * @param int    $serviceUid Optional indexing service UID to filter by (if 0, items from all services are removed)
     *
     * @return void
     *
     * @throws Exception If a database error occurs during the deletion process
     */
    public function deleteByTableAndRecordUIDs(
        string $tableName,
        array $recordUids = [],
        int $serviceUid = 0,
    ): void {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE_NAME);

        $queryBuilder
            ->delete(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'table_name',
                    $queryBuilder->createNamedParameter($tableName)
                )
            );

        if ($recordUids !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'record_uid',
                    $queryBuilder->createNamedParameter(
                        $recordUids,
                        ArrayParameterType::INTEGER
                    )
                )
            );
        }

        if ($serviceUid !== 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'service_uid',
                    $queryBuilder->createNamedParameter(
                        $serviceUid,
                        Connection::PARAM_INT
                    )
                )
            );
        }

        try {
            $queryBuilder->getConnection()->beginTransaction();
            $queryBuilder->executeStatement();
            $queryBuilder->getConnection()->commit();
        } catch (Exception $e) {
            $queryBuilder->getConnection()->rollBack();
            throw $e;
        }
    }
}
