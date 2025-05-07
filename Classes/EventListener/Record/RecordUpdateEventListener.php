<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use TYPO3\CMS\Backend\Utility\BackendUtility;

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
     * @var RecordHandler
     */
    private readonly RecordHandler $recordHandler;

    /**
     * @var RecordRepository
     */
    private readonly RecordRepository $recordRepository;

    /**
     * @var PageRepository
     */
    private readonly PageRepository $pageRepository;

    /**
     * @var DataHandlerRecordUpdateEvent
     */
    private DataHandlerRecordUpdateEvent $event;

    /**
     * Constructor.
     *
     * @param RecordHandler    $recordHandler
     * @param RecordRepository $recordRepository
     * @param PageRepository   $pageRepository
     */
    public function __construct(
        RecordHandler $recordHandler,
        RecordRepository $recordRepository,
        PageRepository $pageRepository,
    ) {
        $this->recordHandler    = $recordHandler;
        $this->recordRepository = $recordRepository;
        $this->pageRepository   = $pageRepository;
    }

    /**
     * Invoke the event listener.
     *
     * @param DataHandlerRecordUpdateEvent $event
     */
    public function __invoke(DataHandlerRecordUpdateEvent $event): void
    {
        $this->event = $event;

        // Determine the root page ID for the event record
        $rootPageId = $this->recordHandler
            ->getRecordRootPageId(
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        $isRecordEnabled = $this->isRecordEnabled(
            $this->event->getTable(),
            $this->event->getRecordUid()
        );

        // Update record at queue and index
        $this->processRecordUpdate(
            $rootPageId,
            $this->event->getRecordUid(),
            $isRecordEnabled
        );

        // Update page if required
        if ($this->isContentElementUpdate()) {
            // TODO Updating the page can be neglected if the changed content element is not taken
            //      into account in the page indexing service.
            $pageId = $this->recordRepository
                ->findPid(
                    ContentIndexer::TABLE,
                    $this->event->getRecordUid()
                );

            // Process page update
            if ($pageId !== false) {
                $this->recordHandler->processPageOfContentElement($rootPageId, $pageId);
            }
        }

        // Handle the update of the page and its content elements
        if ($this->isPageUpdate()) {
            // Update all content elements of the page
            $this->recordHandler
                ->processContentElementsOfPage(
                    $this->event->getRecordUid(),
                    !$isRecordEnabled
                );

            // Get all subpages of the current processed page
            $subPageIds = $this->pageRepository
                ->getPageIdsRecursive(
                    [
                        $this->event->getRecordUid(),
                    ],
                    99,
                    false,
                    true
                );

            // TODO Updates to subpages may only need to be made when visibility has changed and not with every update.
            if ($subPageIds !== []) {
                $this->processRecordUpdates(
                    $rootPageId,
                    $subPageIds,
                    $isRecordEnabled
                );

                foreach ($subPageIds as $subPageId) {
                    // Subpage record is only enabled if the parent page record is also enabled
                    $isSubpageRecordEnabled = $isRecordEnabled
                        && $this->isRecordEnabled(
                            $this->event->getTable(),
                            $subPageId
                        );

                    $this->recordHandler
                        ->processContentElementsOfPage(
                            $subPageId,
                            !$isSubpageRecordEnabled
                        );
                }
            }
        }
    }

    /**
     * Updates the event record at the queue item table and the search engine index.
     *
     * @param int  $rootPageId      The root page UID
     * @param int  $recordUid       The record UID
     * @param bool $isRecordEnabled TRUE if the processed record is enabled or not
     */
    private function processRecordUpdate(int $rootPageId, int $recordUid, bool $isRecordEnabled): void
    {
        $indexerInstanceGenerator = $this->recordHandler
            ->createIndexerGenerator(
                $rootPageId,
                $this->event->getTable(),
            );

        foreach ($indexerInstanceGenerator as $indexingService => $indexerInstance) {
            $this->recordHandler
                ->deleteRecord(
                    $indexingService,
                    $indexerInstance,
                    $this->event->getTable(),
                    $recordUid,
                    !$isRecordEnabled
                );

            // Put the record into the queue to update the index again
            if ($isRecordEnabled) {
                $indexerInstance
                    ->enqueueOne($recordUid);
            }
        }
    }

    /**
     * Updates the event record at the queue item table and the search engine index.
     *
     * @param int   $rootPageId      The root page UID
     * @param int[] $recordUids      The record UIDs
     * @param bool  $isRecordEnabled TRUE if the processed record is enabled or not
     */
    private function processRecordUpdates(int $rootPageId, array $recordUids, bool $isRecordEnabled): void
    {
        $indexerInstanceGenerator = $this->recordHandler
            ->createIndexerGenerator(
                $rootPageId,
                $this->event->getTable(),
            );

        foreach ($indexerInstanceGenerator as $indexingService => $indexerInstance) {
            $this->recordHandler
                ->deleteRecords(
                    $indexingService,
                    $indexerInstance,
                    $this->event->getTable(),
                    $recordUids,
                    !$isRecordEnabled
                );

            // Put the record into the queue to update the index again
            if ($isRecordEnabled) {
                $indexerInstance
                    ->enqueueMultiple($recordUids);
            }
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
            // Record is excluded from search
            || ((($tableName === 'pages') || ($tableName === 'sys_file_metadata'))
                && ($record['no_search'] !== 0))
        );
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
