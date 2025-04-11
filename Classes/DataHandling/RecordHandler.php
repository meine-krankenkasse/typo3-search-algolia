<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\DataHandling;

use Doctrine\DBAL\Exception;
use Generator;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * The record data handler.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordHandler
{
    /**
     * @var SearchEngineFactory
     */
    private readonly SearchEngineFactory $searchEngineFactory;

    /**
     * @var IndexerFactory
     */
    private readonly IndexerFactory $indexerFactory;

    /**
     * @var PageRepository
     */
    private readonly PageRepository $pageRepository;

    /**
     * @var IndexingServiceRepository
     */
    private readonly IndexingServiceRepository $indexingServiceRepository;

    /**
     * @var ContentRepository
     */
    private readonly ContentRepository $contentRepository;

    /**
     * Constructor.
     *
     * @param SearchEngineFactory       $searchEngineFactory
     * @param IndexerFactory            $indexerFactory
     * @param PageRepository            $pageRepository
     * @param IndexingServiceRepository $indexingServiceRepository
     * @param ContentRepository         $contentRepository
     */
    public function __construct(
        SearchEngineFactory $searchEngineFactory,
        IndexerFactory $indexerFactory,
        PageRepository $pageRepository,
        IndexingServiceRepository $indexingServiceRepository,
        ContentRepository $contentRepository,
    ) {
        $this->searchEngineFactory       = $searchEngineFactory;
        $this->indexerFactory            = $indexerFactory;
        $this->pageRepository            = $pageRepository;
        $this->indexingServiceRepository = $indexingServiceRepository;
        $this->contentRepository         = $contentRepository;
    }

    /**
     * Create a generator to return the indexing service and the associated indexer instance.
     *
     * @param int    $rootPageId The root page UID
     * @param string $tableName  The name of the table to be processed
     *
     * @return Generator
     */
    public function createIndexerGenerator(int $rootPageId, string $tableName): Generator
    {
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName($tableName);

        /** @var IndexingService $indexingService */
        foreach ($indexingServices as $indexingService) {
            $indexerInstance = $this->getResponsibleRecordIndexer(
                $indexingService,
                $rootPageId
            );

            if (!($indexerInstance instanceof IndexerInterface)) {
                continue;
            }

            yield $indexingService => $indexerInstance;
        }
    }

    /**
     * Removes the given record from the queue table if it exists and adds it again.
     *
     * @param int    $rootPageId The root page UID
     * @param string $tableName  The name of the table to be processed
     * @param int    $recordUid  The UID of the record to be processed
     *
     * @return void
     *
     * @throws Exception
     */
    public function updateRecordInQueue(int $rootPageId, string $tableName, int $recordUid): void
    {
        $indexerInstanceGenerator = $this->createIndexerGenerator($rootPageId, $tableName);

        foreach ($indexerInstanceGenerator as $indexerInstance) {
            // Remove possible entry of the record from the queue item table
            // and add it again to update index
            $indexerInstance
                ->dequeueOne($recordUid)
                ->enqueueOne($recordUid);
        }
    }

    /**
     * Processes the page of a content element and removes and re-adds it to the queue item table
     * if the page stores the content of content elements along with the page properties.
     *
     * @param int $rootPageId The root page UID
     * @param int $pageId     The UID of the page to be processed
     *
     * @return void
     *
     * @throws Exception
     */
    public function processPageOfContentElement(int $rootPageId, int $pageId): void
    {
        $indexerInstanceGenerator = $this->createIndexerGenerator(
            $rootPageId,
            PageIndexer::TABLE
        );

        foreach ($indexerInstanceGenerator as $indexerInstance) {
            if (!($indexerInstance instanceof PageIndexer)) {
                continue;
            }

            // If the page indexer is configured to include content items in the page index record,
            // we add an additional entry to the queue for the content element's page.
            if (!$indexerInstance->isIncludeContentElements()) {
                continue;
            }

            // Remove possible entry of the record from the queue item table
            // and add it again to update index
            $indexerInstance
                ->dequeueOne($pageId)
                ->enqueueOne($pageId);
        }
    }

    /**
     * Processes all content elements related to the page. Removes or adds elements from the queue and index.
     *
     * @param int  $pageId                    The UID of the page to be processed
     * @param bool $removePageContentElements TRUE to remove elements from queue and index
     *
     * @throws Exception
     */
    public function processContentElementsOfPage(int $pageId, bool $removePageContentElements): void
    {
        // Get all the UIDs of all content elements of this page
        $rowsWithUid = $this->contentRepository
            ->findAllByPid(
                $pageId,
                [
                    'uid',
                ]
            );

        // Get all content element indexer services
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName(ContentIndexer::TABLE);

        /** @var IndexingService $indexingService */
        foreach ($indexingServices as $indexingService) {
            $indexerInstance = $this->indexerFactory
                ->makeInstanceByType($indexingService->getType())
                ?->withIndexingService($indexingService);

            if (!($indexerInstance instanceof ContentIndexer)) {
                continue;
            }

            foreach (array_column($rowsWithUid, 'uid') as $contentElementUid) {
                if ($removePageContentElements) {
                    $this->deleteRecord(
                        $indexingService,
                        $indexerInstance,
                        $indexerInstance->getTable(),
                        $contentElementUid,
                        true
                    );
                } else {
                    $indexerInstance
                        ->enqueueOne($contentElementUid);
                }
            }
        }
    }

    /**
     * Returns the root page ID for the specified table and record UID.
     *
     * @param string $tableName The table name used for the query
     * @param int    $recordUid The UID of the data record to be queried
     *
     * @return int
     */
    public function getRecordRootPageId(string $tableName, int $recordUid): int
    {
        $recordPageId = $recordUid;

        if ($tableName !== 'pages') {
            $recordPageId = $this->getRecordPageId($tableName, $recordUid);
        }

        return $this->pageRepository->getRootPageId($recordPageId);
    }

    /**
     * Returns the ID of the page where the record is stored or 0 if no valid record was found.
     *
     * @param string $tableName The table name from which the record is retrieved
     * @param int    $recordUid The UID of the data record to be retrieved
     *
     * @return int
     */
    private function getRecordPageId(string $tableName, int $recordUid): int
    {
        $record = BackendUtility::getRecord($tableName, $recordUid, 'pid');

        if ($record === null) {
            return 0;
        }

        return $record['pid'] ? ((int) $record['pid']) : 0;
    }

    /**
     * Returns the indexer instance to the given indexing service belonging to the same page tree
     * as the given root page ID.
     *
     * @param IndexingService $indexingService
     * @param int             $rootPageId
     *
     * @return IndexerInterface|null
     */
    private function getResponsibleRecordIndexer(
        IndexingService $indexingService,
        int $rootPageId,
    ): ?IndexerInterface {
        // Determine the root page ID for the indexing service
        $indexingServiceRootPageId = $this->getRecordRootPageId(
            'tx_typo3searchalgolia_domain_model_indexingservice',
            (int) $indexingService->getUid()
        );

        // Ignore this indexing service because the root page IDs do not match,
        // meaning the indexing service is not part of the same page tree.
        if ($rootPageId !== $indexingServiceRootPageId) {
            return null;
        }

        return $this->indexerFactory
            ->makeInstanceByType($indexingService->getType())
            ?->withIndexingService($indexingService);
    }

    /**
     * Removes a record from the queue item table and the search engine index if requested.
     *
     * @param IndexingService  $indexingService
     * @param IndexerInterface $indexerInstance
     * @param string           $tableName
     * @param int              $recordUid
     * @param bool             $isRemoveFromIndex
     *
     * @return void
     */
    public function deleteRecord(
        IndexingService $indexingService,
        IndexerInterface $indexerInstance,
        string $tableName,
        int $recordUid,
        bool $isRemoveFromIndex,
    ): void {
        // Remove possible entry of the record from the queue item table
        $indexerInstance
            ->dequeueOne($recordUid);

        // Remove record from index
        if ($isRemoveFromIndex) {
            $this->deleteRecordFromSearchEngine(
                $indexingService->getSearchEngine(),
                $tableName,
                $recordUid
            );
        }
    }

    /**
     * Removes a record from the search engine index.
     *
     * @param SearchEngine $searchEngine
     * @param string       $tableName
     * @param int          $recordUid
     *
     * @return void
     */
    private function deleteRecordFromSearchEngine(
        SearchEngine $searchEngine,
        string $tableName,
        int $recordUid,
    ): void {
        // Get underlying search engine instance
        $searchEngineService = $this->searchEngineFactory
            ->makeInstanceBySearchEngineModel($searchEngine);

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return;
        }

        $searchEngineService
            ->withIndexName($searchEngine->getIndexName())
            ->deleteFromIndex($tableName, $recordUid);
    }
}
