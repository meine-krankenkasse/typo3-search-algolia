<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Domain\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_diff;
use function array_map;
use function array_values;
use function count;
use function intval;
use function range;

/**
 * Functional tests for QueueItemRepository.
 *
 * Tests all database operations: insert, bulkInsert, delete, findAllLimited, getStatistics.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(QueueItemRepository::class)]
final class QueueItemRepositoryTest extends AbstractFunctionalTestCase
{
    private const string TABLE_NAME = 'tx_typo3searchalgolia_domain_model_queueitem';

    private QueueItemRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $this->subject = $this->get(QueueItemRepository::class);
    }

    /**
     * Tests that insert() adds a single record to the queue item table
     * with the correct table name, record UID and service UID.
     */
    #[Test]
    public function insertAddsRecordToQueue(): void
    {
        $record = [
            'table_name'  => 'pages',
            'record_uid'  => 2,
            'service_uid' => 1,
            'changed'     => 1700000000,
            'priority'    => 0,
        ];

        $affectedRows = $this->subject->insert($record);

        self::assertSame(1, $affectedRows);

        $row = $this->fetchFirstRowByFieldValue(self::TABLE_NAME, 'record_uid', 2);

        self::assertIsArray($row);
        self::assertSame('pages', $row['table_name']);
        self::assertSame(2, (int) $row['record_uid']);
        self::assertSame(1, (int) $row['service_uid']);
        self::assertSame(1700000000, (int) $row['changed']);
        self::assertSame(0, (int) $row['priority']);
    }

    /**
     * Tests that bulkInsert() adds multiple records to the queue
     * in a single operation with correct field values.
     */
    #[Test]
    public function bulkInsertAddsMultipleRecords(): void
    {
        $records = [
            [
                'table_name'  => 'pages',
                'record_uid'  => 10,
                'service_uid' => 1,
                'changed'     => 1700000001,
                'priority'    => 0,
            ],
            [
                'table_name'  => 'pages',
                'record_uid'  => 11,
                'service_uid' => 1,
                'changed'     => 1700000002,
                'priority'    => 0,
            ],
            [
                'table_name'  => 'tt_content',
                'record_uid'  => 20,
                'service_uid' => 2,
                'changed'     => 1700000003,
                'priority'    => 1,
            ],
        ];

        $insertedCount = $this->subject->bulkInsert($records);

        self::assertSame(3, $insertedCount);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $count        = (int) $queryBuilder
            ->count('*')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(3, $count);
    }

    /**
     * Tests that bulkInsert() returns zero and inserts nothing
     * when given an empty array of records.
     */
    #[Test]
    public function bulkInsertReturnsZeroForEmptyArray(): void
    {
        $insertedCount = $this->subject->bulkInsert([]);

        self::assertSame(0, $insertedCount);
    }

    /**
     * Tests that bulkInsert() correctly handles large datasets by
     * chunking them into multiple insert operations.
     */
    #[Test]
    public function bulkInsertChunksLargeDatasets(): void
    {
        $records = [];
        for ($i = 1; $i <= 1050; ++$i) {
            $records[] = [
                'table_name'  => 'pages',
                'record_uid'  => $i,
                'service_uid' => 1,
                'changed'     => 1700000000 + $i,
                'priority'    => 0,
            ];
        }

        $insertedCount = $this->subject->bulkInsert($records);

        self::assertSame(1050, $insertedCount);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $count        = (int) $queryBuilder
            ->count('*')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1050, $count);
    }

    /**
     * Tests that insert() does not fail and keeps a single row when the
     * record is already queued, for example because a second save of the
     * same record put it into the queue between a dequeue and the insert.
     */
    #[Test]
    public function insertIgnoresRecordThatIsAlreadyQueued(): void
    {
        $record = [
            'table_name'  => 'pages',
            'record_uid'  => 2,
            'service_uid' => 1,
            'changed'     => 1700000000,
            'priority'    => 0,
        ];

        self::assertSame(1, $this->subject->insert($record));
        self::assertSame(0, $this->subject->insert($record));

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $count        = (int) $queryBuilder
            ->count('*')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $count);
    }

    /**
     * Tests that bulkInsert() queues the new records of a set and leaves
     * the records that are already queued untouched instead of failing.
     */
    #[Test]
    public function bulkInsertSkipsRecordsThatAreAlreadyQueued(): void
    {
        $this->subject->insert(
            [
                'table_name'  => 'pages',
                'record_uid'  => 1,
                'service_uid' => 1,
                'changed'     => 1700000001,
                'priority'    => 0,
            ]
        );

        $insertedCount = $this->subject->bulkInsert(
            [
                [
                    'table_name'  => 'pages',
                    'record_uid'  => 1,
                    'service_uid' => 1,
                    'changed'     => 1700000009,
                    'priority'    => 0,
                ],
                [
                    'table_name'  => 'pages',
                    'record_uid'  => 2,
                    'service_uid' => 1,
                    'changed'     => 1700000002,
                    'priority'    => 0,
                ],
                [
                    'table_name'  => 'pages',
                    'record_uid'  => 3,
                    'service_uid' => 1,
                    'changed'     => 1700000003,
                    'priority'    => 0,
                ],
                // The same record a second time inside the set
                [
                    'table_name'  => 'pages',
                    'record_uid'  => 2,
                    'service_uid' => 1,
                    'changed'     => 1700000099,
                    'priority'    => 0,
                ],
            ]
        );

        self::assertSame(2, $insertedCount);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $rows         = $queryBuilder
            ->select('record_uid', 'changed')
            ->from(self::TABLE_NAME)
            ->orderBy('record_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(3, $rows, 'Three distinct records in the queue');
        self::assertSame(1700000001, (int) $rows[0]['changed']);
        self::assertSame(1700000002, (int) $rows[1]['changed']);
        self::assertSame(1700000003, (int) $rows[2]['changed']);
    }

    /**
     * Tests that bulkInsert() keeps the records of earlier blocks and counts
     * the new records of every block when a later block contains a record that
     * is already queued.
     */
    #[Test]
    public function bulkInsertCountsNewRecordsOfEveryBlockWhenLaterBlockHasDuplicate(): void
    {
        // The record is queued before, and a record of the set takes its UID below
        $this->subject->insert(
            [
                'table_name'  => 'pages',
                'record_uid'  => 1500,
                'service_uid' => 1,
                'changed'     => 1700000000,
                'priority'    => 0,
            ]
        );

        $records = [];
        for ($i = 1; $i <= 1050; ++$i) {
            $records[] = [
                'table_name'  => 'pages',
                'record_uid'  => $i,
                'service_uid' => 1,
                'changed'     => 1700000000 + $i,
                'priority'    => 0,
            ];
        }

        // Replace a record of the second block by the queued one
        $replacedIndex = 1010;

        self::assertGreaterThan(Constants::IN_LIST_BLOCK_SIZE, $replacedIndex, 'The replaced record is in the second block');
        self::assertGreaterThan(Constants::IN_LIST_BLOCK_SIZE, count($records), 'The set is longer than one block');

        $records[$replacedIndex]['record_uid'] = 1500;

        $insertedCount = $this->subject->bulkInsert($records);

        self::assertSame(1050 - 1, $insertedCount);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $count        = (int) $queryBuilder
            ->count('*')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchOne();

        // Every new record plus the record that was queued before
        self::assertSame(1050, $count);
    }

    /**
     * Tests that bulkInsert() still adds the records of the blocks that follow
     * a block with an already queued record.
     */
    #[Test]
    public function bulkInsertAddsFollowingBlocksWhenFirstBlockHasDuplicate(): void
    {
        $this->subject->insert(
            [
                'table_name'  => 'pages',
                'record_uid'  => 1500,
                'service_uid' => 1,
                'changed'     => 1700000000,
                'priority'    => 0,
            ]
        );

        $records = [];
        for ($i = 1; $i <= 1050; ++$i) {
            $records[] = [
                'table_name'  => 'pages',
                'record_uid'  => $i,
                'service_uid' => 1,
                'changed'     => 1700000000 + $i,
                'priority'    => 0,
            ];
        }

        // Replace a record of the first block by the queued one
        $records[5]['record_uid'] = 1500;

        $insertedCount = $this->subject->bulkInsert($records);

        self::assertSame(1049, $insertedCount);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $inLastBlock  = (int) $queryBuilder
            ->count('*')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->gt('record_uid', 1000))
            ->andWhere($queryBuilder->expr()->lt('record_uid', 1051))
            ->executeQuery()
            ->fetchOne();

        // Every record of the second block, each queued once
        self::assertSame(50, $inLastBlock);
    }

    /**
     * Tests that deleteByTableAndRecordUIDs() removes only the specified
     * records while leaving other records in the queue untouched.
     */
    #[Test]
    public function deleteByTableAndRecordUIDsRemovesSpecificRecords(): void
    {
        // Insert three records
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 1, 'service_uid' => 1, 'changed' => 1700000001, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 2, 'service_uid' => 1, 'changed' => 1700000002, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 3, 'service_uid' => 1, 'changed' => 1700000003, 'priority' => 0]);

        // Delete only records 1 and 3
        $this->subject->deleteByTableAndRecordUIDs('pages', [1, 3]);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $remaining    = $queryBuilder
            ->select('record_uid')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $remaining);
        self::assertSame(2, (int) $remaining[0]['record_uid']);
    }

    /**
     * Tests that deleteByTableAndRecordUIDs() removes the records of a list that
     * is longer than one block, and leaves the records that are not in the list.
     */
    #[Test]
    public function deleteByTableAndRecordUIDsRemovesTheRecordsOfSeveralBlocks(): void
    {
        $records = [];

        for ($i = 1; $i <= 1050; ++$i) {
            $records[] = ['table_name' => 'pages', 'record_uid' => $i, 'service_uid' => 1, 'changed' => 1700000000 + $i, 'priority' => 0];
        }

        self::assertGreaterThan(Constants::IN_LIST_BLOCK_SIZE, count($records), 'The set is longer than one block');

        $this->subject->bulkInsert($records);

        // Everything but two records that sit in different blocks
        $this->subject->deleteByTableAndRecordUIDs(
            'pages',
            array_values(array_diff(range(1, 1050), [7, 1040]))
        );

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $remaining    = $queryBuilder
            ->select('record_uid')
            ->from(self::TABLE_NAME)
            ->orderBy('record_uid')
            ->executeQuery()
            ->fetchFirstColumn();

        self::assertSame([7, 1040], array_map(intval(...), $remaining));
    }

    /**
     * Tests that deleteByTableAndRecordUIDs() removes all records for the
     * given table when no specific record UIDs are provided.
     */
    #[Test]
    public function deleteByTableAndRecordUIDsRemovesAllOfTableWhenNoUids(): void
    {
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 1, 'service_uid' => 1, 'changed' => 1700000001, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 2, 'service_uid' => 1, 'changed' => 1700000002, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'tt_content', 'record_uid' => 10, 'service_uid' => 2, 'changed' => 1700000003, 'priority' => 0]);

        // Delete all pages records (no UIDs specified)
        $this->subject->deleteByTableAndRecordUIDs('pages');

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $remaining    = $queryBuilder
            ->select('table_name')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $remaining);
        self::assertSame('tt_content', $remaining[0]['table_name']);
    }

    /**
     * Tests that deleteByIndexingService() removes all queue records
     * associated with the given indexing service UID.
     */
    #[Test]
    public function deleteByIndexingServiceRemovesAllServiceRecords(): void
    {
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 1, 'service_uid' => 1, 'changed' => 1700000001, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 2, 'service_uid' => 1, 'changed' => 1700000002, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'tt_content', 'record_uid' => 10, 'service_uid' => 2, 'changed' => 1700000003, 'priority' => 0]);

        $indexingService = $this->createMock(IndexingService::class);
        $indexingService
            ->method('getUid')
            ->willReturn(1);

        $this->subject->deleteByIndexingService($indexingService);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $remaining    = $queryBuilder
            ->select('service_uid')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $remaining);
        self::assertSame(2, (int) $remaining[0]['service_uid']);
    }

    /**
     * Tests that getStatistics() returns the correct record counts
     * grouped by table name for all items in the queue.
     */
    #[Test]
    public function getStatisticsReturnsCorrectCounts(): void
    {
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 1, 'service_uid' => 1, 'changed' => 1700000001, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 2, 'service_uid' => 1, 'changed' => 1700000002, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'tt_content', 'record_uid' => 10, 'service_uid' => 2, 'changed' => 1700000003, 'priority' => 0]);

        $statistics = $this->subject->getStatistics();

        self::assertCount(2, $statistics);

        // Statistics are grouped by table_name
        $byTable = [];
        foreach ($statistics as $stat) {
            self::assertIsString($stat['table_name']);

            $byTable[$stat['table_name']] = (int) $stat['count'];
        }

        self::assertSame(2, $byTable['pages']);
        self::assertSame(1, $byTable['tt_content']);
    }

    /**
     * Tests that deleteByTableAndRecordUIDs() respects the optional service
     * UID filter and only removes records matching that service.
     */
    #[Test]
    public function deleteByTableAndRecordUIDsRespectsServiceUidFilter(): void
    {
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 1, 'service_uid' => 1, 'changed' => 1700000001, 'priority' => 0]);
        $this->subject->insert(['table_name' => 'pages', 'record_uid' => 1, 'service_uid' => 2, 'changed' => 1700000002, 'priority' => 0]);

        // Delete only pages record_uid=1 for service_uid=1
        $this->subject->deleteByTableAndRecordUIDs('pages', [1], 1);

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::TABLE_NAME);
        $remaining    = $queryBuilder
            ->select('service_uid')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $remaining);
        self::assertSame(2, (int) $remaining[0]['service_uid']);
    }
}
