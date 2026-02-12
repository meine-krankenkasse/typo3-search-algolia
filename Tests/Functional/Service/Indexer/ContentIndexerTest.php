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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Functional tests for ContentIndexer.
 *
 * Tests enqueue/dequeue operations for tt_content records with real database
 * queries. Verifies that content elements are correctly added to and removed
 * from the indexing queue, respecting page constraints.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(ContentIndexer::class)]
final class ContentIndexerTest extends AbstractFunctionalTestCase
{
    private const string QUEUE_TABLE = 'tx_typo3searchalgolia_domain_model_queueitem';

    private ContentIndexer $contentIndexer;

    private IndexingService $indexingService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();

        $this->contentIndexer = new ContentIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->get(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );

        // Get real IndexingService (uid=2, type=tt_content, pages_recursive=1)
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(2);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }

    // -----------------------------------------------------------------------
    // enqueueOne
    // -----------------------------------------------------------------------

    #[Test]
    public function enqueueOneAddsContentToQueue(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(1);

        self::assertSame(1, $result);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);

        self::assertNotFalse($row);
        self::assertSame('tt_content', $row['table_name']);
        self::assertSame(2, (int) $row['service_uid']);
    }

    #[Test]
    public function enqueueOneReturnsZeroForNonExistentContent(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(999);

        self::assertSame(0, $result);
    }

    // -----------------------------------------------------------------------
    // enqueueMultiple / enqueueAll
    // -----------------------------------------------------------------------

    #[Test]
    public function enqueueMultipleAddsMultipleContentElements(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueMultiple([1, 2]);

        self::assertSame(2, $result);

        $row1 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);
        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);

        self::assertNotFalse($row1);
        self::assertNotFalse($row2);
    }

    #[Test]
    public function enqueueAllAddsAllEligibleContent(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);

        // All 3 content elements are on pages within the recursive tree
        // uid=1 (pid=2), uid=2 (pid=2), uid=3 (pid=3)
        $result = $indexer->enqueueAll();

        self::assertSame(3, $result);
    }

    // -----------------------------------------------------------------------
    // dequeueOne / dequeueMultiple / dequeueAll
    // -----------------------------------------------------------------------

    #[Test]
    public function dequeueOneRemovesContentFromQueue(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueOne(1);

        $indexer->dequeueOne(1);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);

        self::assertFalse($row);
    }

    #[Test]
    public function dequeueMultipleRemovesMultipleContentElements(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueMultiple([1, 2, 3]);

        $indexer->dequeueMultiple([1, 3]);

        $row1 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 1);
        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);
        $row3 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 3);

        self::assertFalse($row1);
        self::assertNotFalse($row2);
        self::assertFalse($row3);
    }

    #[Test]
    public function dequeueAllRemovesAllContentForService(): void
    {
        $indexer = $this->contentIndexer->withIndexingService($this->indexingService);
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
