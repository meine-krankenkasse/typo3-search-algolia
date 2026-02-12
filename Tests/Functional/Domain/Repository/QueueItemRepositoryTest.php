<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Domain\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function bulkInsertReturnsZeroForEmptyArray(): void
    {
        $insertedCount = $this->subject->bulkInsert([]);

        self::assertSame(0, $insertedCount);
    }

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
