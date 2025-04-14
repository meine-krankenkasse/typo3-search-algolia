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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
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
     * @var DataHandlerRecordUpdateEvent
     */
    private DataHandlerRecordUpdateEvent $event;

    /**
     * Constructor.
     *
     * @param RecordHandler    $recordHandler
     * @param RecordRepository $recordRepository
     */
    public function __construct(
        RecordHandler $recordHandler,
        RecordRepository $recordRepository,
    ) {
        $this->recordHandler    = $recordHandler;
        $this->recordRepository = $recordRepository;
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
        $this->processRecordUpdate($rootPageId, $isRecordEnabled);

        // Update page if required
        if ($this->isContentElementUpdate()) {
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
            // Update all content elements of page
            $this->recordHandler
                ->processContentElementsOfPage(
                    $this->event->getRecordUid(),
                    !$isRecordEnabled
                );
        }
    }

    /**
     * Updates the event record at the queue item table and the search engine index.
     *
     * @param int  $rootPageId      The root page UID
     * @param bool $isRecordEnabled TRUE if the processed record is enabled or not
     */
    private function processRecordUpdate(int $rootPageId, bool $isRecordEnabled): void
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
                    $this->event->getRecordUid(),
                    !$isRecordEnabled
                );

            // Put the record into the queue to update the index again
            $indexerInstance
                ->enqueueOne($this->event->getRecordUid());
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
