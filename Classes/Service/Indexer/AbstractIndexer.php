<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Override;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AbstractIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractIndexer implements IndexerInterface
{
    /**
     * @var ConnectionPool
     */
    protected ConnectionPool $connectionPool;

    /**
     * @var SiteFinder
     */
    protected SiteFinder $siteFinder;

    /**
     * @var PageRepository
     */
    protected PageRepository $pageRepository;

    /**
     * @var SearchEngineFactory
     */
    protected SearchEngineFactory $searchEngineFactory;

    /**
     * @var QueueItemRepository
     */
    protected QueueItemRepository $queueItemRepository;

    /**
     * @var DocumentBuilder
     */
    private readonly DocumentBuilder $documentBuilder;

    /**
     * Constructor.
     *
     * @param ConnectionPool      $connectionPool
     * @param SiteFinder          $siteFinder
     * @param PageRepository      $pageRepository
     * @param SearchEngineFactory $searchEngineFactory
     * @param QueueItemRepository $queueItemRepository
     * @param DocumentBuilder     $documentBuilder
     */
    public function __construct(
        ConnectionPool $connectionPool,
        SiteFinder $siteFinder,
        PageRepository $pageRepository,
        SearchEngineFactory $searchEngineFactory,
        QueueItemRepository $queueItemRepository,
        DocumentBuilder $documentBuilder,
    ) {
        $this->connectionPool      = $connectionPool;
        $this->siteFinder          = $siteFinder;
        $this->pageRepository      = $pageRepository;
        $this->searchEngineFactory = $searchEngineFactory;
        $this->queueItemRepository = $queueItemRepository;
        $this->documentBuilder     = $documentBuilder;
    }

    #[Override]
    public function enqueue(IndexingService $indexingService): int
    {
        $this->queueItemRepository
            ->deleteByIndexingService($indexingService);

        return $this->queueItemRepository
            ->bulkInsert(
                $this->queryItems($indexingService)
            );
    }

    #[Override]
    public function indexRecord(IndexingService $indexingService, array $record): bool
    {
        $searchEngineService = $this->searchEngineFactory
            ->createBySubtype(
                $indexingService
                    ->getSearchEngine()
                    ->getEngine()
            );

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return false;
        }

        // Build the document
        $document = $this->documentBuilder
            ->setIndexer($this)
            ->setRecord($record)
            ->assemble()
            ->getDocument();

        $searchEngineService->indexOpen(
            $indexingService->getSearchEngine()->getIndexName()
        );

        $result = $searchEngineService->documentUpdate($document);

        $searchEngineService->indexCommit();
        $searchEngineService->indexClose();

        return $result;
    }

    /**
     * Returns records from the current indexer table matching certain constraints.
     *
     * @param IndexingService $indexingService
     *
     * @return array<int, array<string, int|string>>
     *
     * @throws Exception
     */
    private function queryItems(IndexingService $indexingService): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($this->getTable());

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DefaultRestrictionContainer::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class));

        $constraints = [];

        if ($this->getType() === PageIndexer::TYPE) {
            $constraints[] = $queryBuilder->expr()->in(
                'uid',
                $this->getPages($indexingService),
            );
        } else {
            $constraints[] = $queryBuilder->expr()->in(
                'pid',
                $this->getPages($indexingService),
            );
        }

        // Add indexer related constraints
        $constraints = array_merge(
            $constraints,
            $this->getQueryItemsConstraints()
        );

        return $queryBuilder
            ->select(
                'uid AS record_uid',
            )
            ->addSelectLiteral(
                "'" . $this->getTable() . "' as table_name",
                "'" . $this->getType() . "' AS indexer_type",
                "'" . $indexingService->getUid() . "' AS service_uid",
                $this->getChangedFieldStatement() . ' AS changed',
                '0 AS priority'
            )
            ->from($this->getTable())
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Returns indexer related query builder constraints.
     *
     * @return string[]
     */
    protected function getQueryItemsConstraints(): array
    {
        return [];
    }

    /**
     * Returns all page UIDs of all sites.
     *
     * @param IndexingService $indexingService
     *
     * @return int[]
     */
    protected function getPages(IndexingService $indexingService): array
    {
        $sites   = $this->siteFinder->getAllSites(false);
        $pageIds = [[]];

        // TODO Limit to single sites (configurable)?
        foreach ($sites as $site) {
            $pageIds[] = $this->pageRepository->getPageIdsRecursive(
                [
                    $site->getRootPageId(),
                ],
                9999
            );
        }

        return array_merge(...$pageIds);
    }

    /**
     * Returns the SQL select statement to determine the latest change timestamp.
     *
     * @return string
     */
    private function getChangedFieldStatement(): string
    {
        if (
            isset($GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime'])
            && ($GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime'] !== '')
        ) {
            return 'GREATEST(' . $GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime']
                . ', ' . $GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'] . ')';
        }

        return $GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'];
    }
}
