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
 * Functional test proving AbstractIndexer::fetchRecords()'s
 * "$GLOBALS['TCA'][...]['ctrl']['tstamp'] ?? 'uid'" fallback is genuinely
 * reachable and correctly wired as the ORDER BY column, not merely a
 * theoretical branch for a hypothetical future indexer.
 *
 * Uses the same technique AttributeOverviewModuleControllerTest::setUp()
 * already relies on for tx_news_domain_model_news (directly mutating
 * $GLOBALS['TCA']), applied here to the real 'pages' table: 'pages' TCA
 * always defines ctrl.tstamp in a real TYPO3 bootstrap, so this test
 * temporarily removes that key to construct the "table's TCA has no
 * ctrl.tstamp" scenario the fallback exists for, then drives
 * PageIndexer::findRecordUidsInScope() (which calls fetchRecords()
 * internally) against it.
 *
 * Deliberately a separate test class from
 * AbstractIndexerFindRecordUidsInScopeFunctionalTest, but reuses the SAME
 * fixture (pages_scope_ordering.csv) and asserts the OPPOSITE order for the
 * identical rows: that other test's LIMIT 2 (tstamp intact) returns [2, 3]
 * (highest tstamps), this one's LIMIT 2 (tstamp removed) returns [3, 2]
 * (highest UIDs). Two different orders read off literally the same fixture
 * data is exactly what proves the fallback field, not tstamp, actually
 * drove the ORDER BY, not merely that a query happened to return something.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AbstractIndexer::class)]
#[CoversClass(PageIndexer::class)]
final class AbstractIndexerFindRecordUidsInScopeTstampFallbackFunctionalTest extends AbstractFunctionalTestCase
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
            self::createStub(SiteFinder::class),
            new PageRepository($connectionPool),
            $this->createMock(SearchEngineFactory::class),
            $this->get(QueueItemRepository::class),
            $this->createMock(DocumentBuilder::class),
        );

        // Real IndexingService (uid=1, type=pages, pages_recursive=1, pages_doktype=1),
        // same fixture row PageIndexerTest and
        // AbstractIndexerFindRecordUidsInScopeFunctionalTest use.
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(1);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;

        // Construct the "table's TCA has no ctrl.tstamp" scenario:
        // AbstractIndexer::fetchRecords() reads $GLOBALS['TCA'] directly for
        // its ORDER BY column (not via the built TcaSchemaFactory schema),
        // so mutating the raw array is sufficient here, unlike
        // NewsIndexerTest's synthetic-TCA setup, which also needs a schema
        // rebuild because it changes enablecolumns-driven restrictions. Set
        // to null rather than unset(): both are equivalent for the ??
        // fallback under test (null coalescing treats a missing key and an
        // explicit null value identically), but null avoids an "Undefined
        // array key" PHP warning from fetchRecords()'s OTHER, unrelated
        // ctrl.tstamp read in getChangedFieldStatement() (used to build the
        // 'changed' select literal, not the ORDER BY this test targets).
        // enablecolumns.starttime is cleared for the same reason: 'pages'
        // TCA defines it, which would otherwise route
        // getChangedFieldStatement() into its GREATEST(starttime, tstamp)
        // branch and concatenate the now-null tstamp into malformed SQL.
        // Neither change reaches the restriction filtering that already ran
        // via the schema TcaSchemaFactory built during bootstrap (before
        // this setUp() mutates the raw array), only these two ad-hoc reads.
        $GLOBALS['TCA']['pages']['ctrl']['tstamp'] = null;
        unset($GLOBALS['TCA']['pages']['ctrl']['enablecolumns']['starttime']);
    }

    /**
     * Fixture tstamps: uid=1 -> 1000, uid=2 -> 3000, uid=3 -> 2000 (same
     * pages_scope_ordering.csv AbstractIndexerFindRecordUidsInScopeFunctionalTest
     * uses). With ctrl.tstamp removed, fetchRecords()'s fallback orders by
     * 'uid' DESC instead, so a LIMIT 2 must return the two highest UIDs (3,
     * 2), the opposite of that other test's tstamp-ordered [2, 3] for the
     * identical rows, proving the fallback field is genuinely 'uid', not
     * NULL, not silently dropped, and not the pre-existing tstamp column.
     */
    #[Test]
    public function findRecordUidsInScopeWithALimitOrdersByUidWhenTheTableHasNoTstampField(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $recordUids = $indexer->findRecordUidsInScope(2);

        self::assertSame([3, 2], $recordUids);
    }
}
