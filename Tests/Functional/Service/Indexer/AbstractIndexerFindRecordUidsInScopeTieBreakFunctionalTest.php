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
 * Functional test for AbstractIndexer::findRecordUidsInScope() proving the
 * uid DESC tie-break in AbstractIndexer::fetchRecords() is actually
 * load-bearing when two in-scope rows share the EXACT SAME tstamp, not just
 * present in that method's docblock.
 *
 * Deliberately separate from AbstractIndexerFindRecordUidsInScopeFunctionalTest
 * (and its pages_scope_ordering.csv fixture, whose three rows all carry
 * distinct tstamps and therefore never exercise the secondary sort key) so
 * neither test's fixture data nor assertions have to change to make room for
 * a tie, using its own dedicated fixture (pages_scope_ordering_tie.csv)
 * instead.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AbstractIndexer::class)]
#[CoversClass(PageIndexer::class)]
final class AbstractIndexerFindRecordUidsInScopeTieBreakFunctionalTest extends AbstractFunctionalTestCase
{
    private PageIndexer $pageIndexer;

    private IndexingService $indexingService;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages_scope_ordering_tie.csv');
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
        // same fixture row PageIndexerTest and
        // AbstractIndexerFindRecordUidsInScopeFunctionalTest use.
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(1);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }

    /**
     * Fixture tstamps: uid=1 -> 1000, uid=2 -> 3000, uid=3 -> 3000 (uid=2 and
     * uid=3 tied for the highest tstamp). A LIMIT 2 without the uid DESC
     * tie-break would, on the actual test database engine, return the tied
     * rows in their natural/insertion order (uid 2, then uid 3) - this
     * assertion is order-sensitive specifically so that outcome would fail
     * it. With the tie-break, uid=3 (the higher of the two tied uids) must
     * sort first, followed by uid=2; uid=1 (the lowest tstamp) is excluded
     * by the limit either way.
     */
    #[Test]
    public function findRecordUidsInScopeWithALimitOrdersTiedTstampsByUidDescending(): void
    {
        $indexer = $this->pageIndexer->withIndexingService($this->indexingService);

        $recordUids = $indexer->findRecordUidsInScope(2);

        self::assertSame([3, 2], $recordUids);
    }
}
