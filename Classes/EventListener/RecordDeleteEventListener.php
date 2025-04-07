<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * The record delete event listener. This event listener is called when an existing record is deleted.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordDeleteEventListener
{
    /**
     * @var DataHandler
     */
    private readonly DataHandler $dataHandler;

    /**
     * @var RecordHandler
     */
    private readonly RecordHandler $recordHandler;

    /**
     * @var IndexingServiceRepository
     */
    private readonly IndexingServiceRepository $indexingServiceRepository;

    /**
     * @var DataHandlerRecordDeleteEvent
     */
    private DataHandlerRecordDeleteEvent $event;

    /**
     * Constructor.
     *
     * @param DataHandler               $dataHandler
     * @param RecordHandler             $recordHandler
     * @param IndexingServiceRepository $indexingServiceRepository
     */
    public function __construct(
        DataHandler $dataHandler,
        RecordHandler $recordHandler,
        IndexingServiceRepository $indexingServiceRepository,
    ) {
        $this->dataHandler               = $dataHandler;
        $this->recordHandler             = $recordHandler;
        $this->indexingServiceRepository = $indexingServiceRepository;
    }

    /**
     * Invoke the event listener.
     *
     * @param DataHandlerRecordDeleteEvent $event
     */
    public function __invoke(DataHandlerRecordDeleteEvent $event): void
    {
        $this->event = $event;

        // Determine the root page ID for the event record
        $rootPageId = $this->recordHandler
            ->getRecordRootPageId(
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        // Remove record from queue and index
        $this->processRecordDeletion($rootPageId);

        // Update page if required
        if ($this->isContentElementUpdate()) {
            // Alternatively, replace with BackendUtility::getRecord()
            $pageUid = $this->dataHandler
                ->getPID(
                    ContentIndexer::TABLE,
                    $this->event->getRecordUid()
                );

            // Process page update
            if ($pageUid !== false) {
                $indexingServices = $this->indexingServiceRepository
                    ->findAllByTableName(PageIndexer::TABLE);

                /** @var IndexingService $indexingService */
                foreach ($indexingServices as $indexingService) {
                    $indexerInstance = $this->recordHandler
                        ->getResponsibleRecordIndexer(
                            $indexingService,
                            $rootPageId
                        );

                    if (!($indexerInstance instanceof PageIndexer)) {
                        continue;
                    }

                    // If the page indexer is configured to include content items in the page index record,
                    // we add an additional entry to the queue for the content item's page.
                    if (!$indexerInstance->isIncludeContentElements()) {
                        continue;
                    }

                    // Remove possible entry of the record from the queue item table
                    // and add it again to update index
                    $indexerInstance
                        ->dequeueOne($pageUid)
                        ->enqueueOne($pageUid);
                }
            }
        }

        // Handle page deletion and related content elements
        if ($this->isPageUpdate()) {
            // Page update with all content elements requested
            $this->recordHandler
                ->processContentElementsOfPage(
                    $this->event->getRecordUid(),
                    true
                );
        }
    }

    /**
     * Removes the event record from the queue item table and the search engine index.
     *
     * @param int $rootPageId
     */
    private function processRecordDeletion(int $rootPageId): void
    {
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName($this->event->getTable());

        /** @var IndexingService $indexingService */
        foreach ($indexingServices as $indexingService) {
            $indexerInstance = $this->recordHandler
                ->getResponsibleRecordIndexer(
                    $indexingService,
                    $rootPageId
                );

            if (!($indexerInstance instanceof IndexerInterface)) {
                continue;
            }

            // Remove the record from the queue item table
            $indexerInstance
                ->dequeueOne($this->event->getRecordUid());

            // Remove record from index
            $this->recordHandler
                ->deleteRecordFromSearchEngine(
                    $indexingService->getSearchEngine(),
                    $this->event->getTable(),
                    $this->event->getRecordUid()
                );
        }
    }

    /**
     * Returns TRUE if a content element update is performed.
     *
     * @return bool
     */
    private function isContentElementUpdate(): bool
    {
        return $this->event->getTable() === 'tt_content';
    }

    /**
     * Returns TRUE if a page update is performed.
     *
     * @return bool
     */
    private function isPageUpdate(): bool
    {
        return $this->event->getTable() === 'pages';
    }
}
