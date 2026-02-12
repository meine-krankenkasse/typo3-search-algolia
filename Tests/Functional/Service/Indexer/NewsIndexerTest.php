<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\NewsIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Functional tests for NewsIndexer.
 *
 * Tests enqueue/dequeue operations for tx_news_domain_model_news records
 * with real database queries. Since the news extension is not a hard
 * dependency, the test creates the required database table and TCA
 * configuration manually.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(NewsIndexer::class)]
final class NewsIndexerTest extends AbstractFunctionalTestCase
{
    private const string QUEUE_TABLE = 'tx_typo3searchalgolia_domain_model_queueitem';

    private NewsIndexer $newsIndexer;

    private IndexingService $indexingService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Create the news table from SQL fixture since the news extension is not loaded
        $sql = file_get_contents(__DIR__ . '/../../Fixtures/Database/create_tx_news_domain_model_news.sql');
        self::assertIsString($sql);

        $this->getConnectionPool()
            ->getConnectionByName('Default')
            ->executeStatement($sql);

        // Set up TCA for the news table so DefaultRestrictionContainer works
        $GLOBALS['TCA']['tx_news_domain_model_news'] = [
            'ctrl' => [
                'tstamp'        => 'tstamp',
                'crdate'        => 'crdate',
                'delete'        => 'deleted',
                'enablecolumns' => [
                    'disabled'  => 'hidden',
                    'starttime' => 'starttime',
                    'endtime'   => 'endtime',
                ],
                'languageField'            => 'sys_language_uid',
                'transOrigPointerField'    => 'l10n_parent',
                'transOrigDiffSourceField' => 'l10n_diffsource',
            ],
        ];

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_news_domain_model_news.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();

        $this->newsIndexer = new NewsIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->get(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );

        // Get real IndexingService (uid=3, type=tx_news_domain_model_news, pages_recursive=1)
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(3);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }

    // -----------------------------------------------------------------------
    // enqueueOne
    // -----------------------------------------------------------------------

    /**
     * Tests that enqueueOne() adds a single news record to the indexing
     * queue with the correct table name, record UID and service UID.
     */
    #[Test]
    public function enqueueOneAddsNewsToQueue(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(1);

        self::assertSame(1, $result);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);

        self::assertNotFalse($row);
        self::assertSame('tx_news_domain_model_news', $row['table_name']);
        self::assertSame(3, (int) $row['service_uid']);
    }

    /**
     * Tests that enqueueOne() returns zero when the specified news
     * record does not exist in the database.
     */
    #[Test]
    public function enqueueOneReturnsZeroForNonExistentNews(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(999);

        self::assertSame(0, $result);
    }

    /**
     * Tests that enqueueOne() returns zero for a hidden news record,
     * since hidden records are excluded by the default restrictions.
     */
    #[Test]
    public function enqueueOneReturnsZeroForHiddenNews(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);

        // News uid=4 has hidden=1
        $result = $indexer->enqueueOne(4);

        self::assertSame(0, $result);
    }

    /**
     * Tests that enqueueOne() returns zero for a deleted news record,
     * since deleted records are excluded by the default restrictions.
     */
    #[Test]
    public function enqueueOneReturnsZeroForDeletedNews(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);

        // News uid=5 has deleted=1
        $result = $indexer->enqueueOne(5);

        self::assertSame(0, $result);
    }

    // -----------------------------------------------------------------------
    // enqueueMultiple / enqueueAll
    // -----------------------------------------------------------------------

    /**
     * Tests that enqueueMultiple() adds multiple news records to
     * the indexing queue in a single operation.
     */
    #[Test]
    public function enqueueMultipleAddsMultipleNews(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueMultiple([1, 2]);

        self::assertSame(2, $result);

        $row1 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);
        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);

        self::assertNotFalse($row1);
        self::assertNotFalse($row2);
    }

    /**
     * Tests that enqueueAll() adds all eligible news records, excluding
     * hidden and deleted records, to the indexing queue.
     */
    #[Test]
    public function enqueueAllAddsAllEligibleNews(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);

        // Eligible: uid=1 (pid=2), uid=2 (pid=2), uid=3 (pid=3)
        // Not eligible: uid=4 (hidden), uid=5 (deleted)
        $result = $indexer->enqueueAll();

        self::assertSame(3, $result);
    }

    // -----------------------------------------------------------------------
    // dequeueOne / dequeueMultiple / dequeueAll
    // -----------------------------------------------------------------------

    /**
     * Tests that dequeueOne() removes a single news record from
     * the indexing queue by its record UID.
     */
    #[Test]
    public function dequeueOneRemovesNewsFromQueue(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueOne(1);

        $indexer->dequeueOne(1);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);

        self::assertFalse($row);
    }

    /**
     * Tests that dequeueMultiple() removes only the specified news
     * records from the queue while leaving others untouched.
     */
    #[Test]
    public function dequeueMultipleRemovesMultipleNews(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueMultiple([1, 2, 3]);

        $indexer->dequeueMultiple([1, 3]);

        $row1 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);
        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);
        $row3 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 3);

        self::assertFalse($row1);
        self::assertNotFalse($row2);
        self::assertFalse($row3);
    }

    /**
     * Tests that dequeueAll() removes all queue items associated with
     * the indexing service, regardless of individual record UIDs.
     */
    #[Test]
    public function dequeueAllRemovesAllNewsForService(): void
    {
        $indexer = $this->newsIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueAll();

        $indexer->dequeueAll();

        $queryBuilder = $this->getQueryBuilderWithoutRestrictions(self::QUEUE_TABLE);
        $count        = (int) $queryBuilder
            ->count('*')
            ->from(self::QUEUE_TABLE)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, $count);
    }
}
