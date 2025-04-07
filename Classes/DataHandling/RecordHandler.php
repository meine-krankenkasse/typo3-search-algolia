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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
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
    public function getResponsibleRecordIndexer(
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
     * Removes a record from the search engine index.
     *
     * @param SearchEngine $searchEngine
     * @param string       $tableName
     * @param int          $recordUid
     *
     * @return void
     */
    public function deleteRecordFromSearchEngine(
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

    /**
     * Processes all content element related to the page.
     *
     * @param int  $pageId                    The page UID to process
     * @param bool $removePageContentElements TRUE to remove elements from queue and index
     *
     * @throws Exception
     */
    public function processContentElementsOfPage(
        int $pageId,
        bool $removePageContentElements,
    ): void {
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

            if (!($indexerInstance instanceof IndexerInterface)) {
                continue;
            }

            foreach (array_column($rowsWithUid, 'uid') as $contentElementUid) {
                if ($removePageContentElements) {
                    $indexerInstance
                        ->dequeueOne($contentElementUid);

                    // Remove record from index
                    $this->deleteRecordFromSearchEngine(
                        $indexingService->getSearchEngine(),
                        $indexerInstance->getTable(),
                        $contentElementUid
                    );
                } else {
                    $indexerInstance
                        ->enqueueOne($contentElementUid);
                }
            }
        }
    }
}
