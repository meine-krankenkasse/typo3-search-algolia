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
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Shared setup for the AbstractIndexer::findRecordUidsInScope() functional
 * test classes in this directory.
 *
 * Each subclass exercises findRecordUidsInScope() against its own dedicated
 * page fixture (they intentionally use separate fixtures, see each
 * subclass's own docblock), but all of them drive the same PageIndexer
 * (built with identical collaborators) against the same real IndexingService
 * fixture row (uid=1, type=pages, pages_recursive=1, pages_doktype=1, same
 * row PageIndexerTest uses). This base class extracts that identical
 * construction so subclasses only supply the page fixture path.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractIndexerFindRecordUidsInScopeTestCase extends AbstractFunctionalTestCase
{
    protected PageIndexer $pageIndexer;

    protected IndexingService $indexingService;

    /**
     * Imports the given page fixture plus the shared search engine and
     * indexing service fixtures, then builds $this->pageIndexer and
     * $this->indexingService for the test to use.
     *
     * @param non-empty-string $pageFixturePath Absolute path to the page fixture CSV to import
     */
    protected function createPageIndexerWithIndexingService(string $pageFixturePath): void
    {
        $this->importCSVDataSet($pageFixturePath);
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
        // same fixture row PageIndexerTest uses.
        $repository      = $this->get(IndexingServiceRepository::class);
        $indexingService = $repository->findByUid(1);

        self::assertInstanceOf(IndexingService::class, $indexingService);

        $this->indexingService = $indexingService;
    }
}
