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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * The record update event listener. This event listener is called when
 * a new record is created or an existing record is changed.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordUpdateEventListener
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
     * @var DataHandlerRecordUpdateEvent
     */
    private DataHandlerRecordUpdateEvent $event;

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
     * @param DataHandlerRecordUpdateEvent $event
     */
    public function __invoke(DataHandlerRecordUpdateEvent $event): void
    {
        $this->event = $event;

        // The following considerations for the process precede:
        //
        // - Determine the indexer responsible for $event->getTable()
        //   - Currently, only one indexer is responsible/possible for each table
        // - Read the record using $event->getRecordUid()
        // - Determine the root page ID for the record
        // - Determine all configured indexing services created below the root page ID
        // - Handle the existing entry for the record in the queue table
        // - Perform indexing for all found indexing services

        $pageUid = $this->event->getRecordUid();

        // Determine the page ID on which the content element is located.
        // This will be needed later when the page indexer has been configured to include the content elements
        // in the page index record.
        if ($this->isContentElementUpdate()) {
            // Alternatively, replace with BackendUtility::getRecord()
            $pageUid = $this->dataHandler->getPID('tt_content', $this->event->getRecordUid());
        }

        // Determine the root page ID for the event record
        $rootPageId = $this->recordHandler
            ->getRecordRootPageId(
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        // Determine all configured indexing services that are created below the root page ID
        $indexingServices = $this->indexingServiceRepository->findAll();

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

            $recordUid = $this->event->getRecordUid();

            // If this is the page indexer, and it is configured to include content items in the page index record,
            // we add an additional entry to the queue for the content item's page.
            $isContentElementRequestsPageUpdate = ($indexerInstance instanceof PageIndexer)
                && $indexerInstance->isIncludeContentElements()
                && $this->isContentElementUpdate();

            if ($isContentElementRequestsPageUpdate) {
                if ($pageUid !== false) {
                    $recordUid = $pageUid;
                }
            } elseif ($indexerInstance->getTable() !== $this->event->getTable()) {
                // Indexer is not responsible for this kind of table
                continue;
            }

            // Remove the record from the queue item table
            $indexerInstance
                ->dequeueOne($recordUid);

            if (!$isContentElementRequestsPageUpdate) {
                $removePageContentElements = false;

                // Remove record from index if it is not available anymore
                if (!$this->isRecordEnabled($this->event->getTable(), $this->event->getRecordUid())) {
                    $removePageContentElements = true;

                    // Remove record from index
                    $this->recordHandler
                        ->deleteRecordFromSearchEngine(
                            $indexingService->getSearchEngine(),
                            $this->event->getTable(),
                            $this->event->getRecordUid()
                        );
                }

                // Page update with all content elements requested
                if (
                    $this->isPageUpdate()
                    && $indexingService->isIncludeContentElements()
                ) {
                    $this->recordHandler
                        ->processContentElementsOfPage(
                            $this->event->getRecordUid(),
                            $removePageContentElements
                        );
                }
            }

            // Put the record into the queue (puts only enabled records into queue)
            $indexerInstance
                ->enqueueOne($recordUid);
        }
    }

    /**
     * Returns TRUE if the record is enabled otherwise FALSE.
     *
     * @param string $tableName
     * @param int    $recordUid
     *
     * @return bool
     */
    private function isRecordEnabled(string $tableName, int $recordUid): bool
    {
        $record = BackendUtility::getRecord($tableName, $recordUid) ?? [];

        return !(
            ($record === [])
            || (isset($GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns']['disabled'])
                && ($record[$GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns']['disabled']] !== 0))
            || (isset($GLOBALS['TCA'][$tableName]['ctrl']['delete'])
                && ($record[$GLOBALS['TCA'][$tableName]['ctrl']['delete']] !== 0))
            || (($tableName === 'pages') && ($record['no_search'] !== 0)));
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
