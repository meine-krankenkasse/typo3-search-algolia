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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\AbstractIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Functional tests for AbstractIndexer::findRecordUidsInScope() against a
 * real database, specifically covering the interaction between a positive
 * $limit and result ordering.
 *
 * Uses its own dedicated fixture (pages_scope_ordering.csv, deliberately
 * separate from pages.csv used by PageIndexerTest) with explicit, distinct
 * tstamp values per page, since pages.csv leaves tstamp at its column
 * default (0) for every row, which cannot discriminate an ORDER BY tstamp
 * DESC from no ordering at all.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AbstractIndexer::class)]
#[CoversClass(PageIndexer::class)]
final class AbstractIndexerFindRecordUidsInScopeFunctionalTest extends AbstractFunctionalTestCase
{
    private PageIndexer $pageIndexer;

    private IndexingService $indexingService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages_scope_ordering.csv');
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

        // Real IndexingService (uid=1, type=pages, pages_recursive=1, pages_doktype=1),
        // same fixture row PageIndexerTest uses.
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(1);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }

    /**
     * Without a limit, all three in-scope pages (uid 1, 2, 3, all
     * doktype=1/hidden=0/no_search=0) come back, order is not asserted here
     * since the unbounded case intentionally applies no ORDER BY (see
     * AbstractIndexer::fetchRecords()'s docblock).
     */
    #[Test]
    public function findRecordUidsInScopeReturnsEveryInScopePageWithoutALimit(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $recordUids = $indexer->findRecordUidsInScope();

        self::assertCount(3, $recordUids);
        self::assertEqualsCanonicalizing([1, 2, 3], $recordUids);
    }

    /**
     * Proves the capped query is ordered by tstamp DESC (uid DESC tie-break)
     * BEFORE the SQL LIMIT is applied, not merely capped in whatever
     * unspecified order the database happens to return rows in.
     *
     * Fixture tstamps: uid=1 -> 1000, uid=2 -> 3000 (highest), uid=3 -> 2000.
     * A LIMIT 2 without ORDER BY would, on the actual test database engine,
     * return rows in their natural/insertion order (uid 1, 2), silently
     * dropping uid=3 even though its tstamp (2000) is higher than uid=1's
     * (1000) - exactly the "wrong record silently picked" defect this fix
     * closes. The correctly ordered result is uid 2 (tstamp 3000) and uid 3
     * (tstamp 2000), excluding uid 1 (the lowest tstamp).
     */
    #[Test]
    public function findRecordUidsInScopeWithALimitReturnsTheMostRecentlyChangedRecords(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $recordUids = $indexer->findRecordUidsInScope(2);

        self::assertSame([2, 3], $recordUids);
    }
}
