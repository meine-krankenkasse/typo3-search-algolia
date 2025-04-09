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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
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
     * @var DataHandlerRecordDeleteEvent
     */
    private DataHandlerRecordDeleteEvent $event;

    /**
     * Constructor.
     *
     * @param DataHandler   $dataHandler
     * @param RecordHandler $recordHandler
     */
    public function __construct(
        DataHandler $dataHandler,
        RecordHandler $recordHandler,
    ) {
        $this->dataHandler   = $dataHandler;
        $this->recordHandler = $recordHandler;
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
        $this->processRecordDelete($rootPageId);

        // Update page if required
        if ($this->isContentElementUpdate()) {
            // Alternatively, replace with BackendUtility::getRecord()
            $pageId = $this->dataHandler
                ->getPID(
                    ContentIndexer::TABLE,
                    $this->event->getRecordUid()
                );

            // Process page update
            if ($pageId !== false) {
                $this->recordHandler->processPage($rootPageId, $pageId);
            }
        }

        // Handle the deletion of the page and its content elements
        if ($this->isPageUpdate()) {
            // Remove all content elements from queue and index
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
     * @param int $rootPageId The root page UID
     */
    private function processRecordDelete(int $rootPageId): void
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
                    true
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
