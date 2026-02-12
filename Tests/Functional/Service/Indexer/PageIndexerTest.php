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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Functional tests for PageIndexer.
 *
 * Tests enqueue/dequeue operations with real database queries. Verifies that
 * page records are correctly added to and removed from the indexing queue,
 * respecting page constraints (doktype, no_search, hidden, recycler).
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(PageIndexer::class)]
final class PageIndexerTest extends AbstractFunctionalTestCase
{
    private const string QUEUE_TABLE = 'tx_typo3searchalgolia_domain_model_queueitem';

    private PageIndexer $pageIndexer;

    private IndexingService $indexingService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();

        $this->pageIndexer = new PageIndexer(
            $connectionPool,
            $this->createMock(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->get(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );

        // Get real IndexingService (uid=1, type=pages, pages_recursive=1, pages_doktype=1)
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(1);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }

    // -----------------------------------------------------------------------
    // enqueueOne
    // -----------------------------------------------------------------------

    #[Test]
    public function enqueueOneAddsPageToQueue(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(2);

        self::assertSame(1, $result);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);

        self::assertNotFalse($row);
        self::assertSame('pages', $row['table_name']);
        self::assertSame(1, (int) $row['service_uid']);
    }

    #[Test]
    public function enqueueOneReturnsZeroForNonExistentPage(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueOne(999);

        self::assertSame(0, $result);
    }

    #[Test]
    public function enqueueOneReturnsZeroForNoSearchPage(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        // Page uid=6 has no_search=1, so PageIndexer's constraint excludes it
        $result = $indexer->enqueueOne(6);

        self::assertSame(0, $result);
    }

    #[Test]
    public function enqueueOneReturnsZeroForRecyclerPage(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        // Page uid=5 has doktype=255, not in allowed list (pages_doktype=1)
        $result = $indexer->enqueueOne(5);

        self::assertSame(0, $result);
    }

    // -----------------------------------------------------------------------
    // enqueueMultiple / enqueueAll
    // -----------------------------------------------------------------------

    #[Test]
    public function enqueueMultipleAddsMultiplePages(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $result = $indexer->enqueueMultiple([2, 3]);

        self::assertSame(2, $result);

        $row2 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);
        $row3 = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 3);

        self::assertNotFalse($row2);
        self::assertNotFalse($row3);
    }

    #[Test]
    public function enqueueAllAddsAllEligiblePages(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        // Eligible: uid=1 (root, doktype=1), uid=2 (sub, doktype=1), uid=3 (deep, doktype=1)
        // Not eligible: uid=4 (hidden), uid=5 (doktype=255), uid=6 (no_search=1)
        $result = $indexer->enqueueAll();

        self::assertSame(3, $result);
    }

    // -----------------------------------------------------------------------
    // dequeueOne / dequeueMultiple / dequeueAll
    // -----------------------------------------------------------------------

    #[Test]
    public function dequeueOneRemovesPageFromQueue(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
        $indexer->enqueueOne(2);

        $indexer->dequeueOne(2);

        $row = $this->fetchFirstRowByFieldValue(self::QUEUE_TABLE, 'record_uid', 2);

        self::assertFalse($row);
    }

    #[Test]
    public function dequeueMultipleRemovesMultiplePages(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
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
    public function dequeueAllRemovesAllPagesForService(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);
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
