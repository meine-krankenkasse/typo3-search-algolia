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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;

/**
 * Event listener for handling record move operations in the search indexing system.
 *
 * This listener responds to DataHandlerRecordMoveEvent events that are dispatched
 * when records are moved in the TYPO3 backend or through the DataHandler API.
 * It ensures that the search index is updated to reflect the new location of the record.
 *
 * The listener performs the following tasks:
 * - Verifies that the record has actually changed location (different target and source PIDs)
 * - Determines the root page ID for the moved record to establish the correct indexing context
 * - Updates the record in the indexing queue to ensure it will be re-indexed
 * - For content elements, also processes the page that previously contained the element
 *   to ensure that page's index entry is updated to reflect the removal of the content
 *
 * This listener is essential for maintaining the integrity of the search index
 * when content is reorganized within the TYPO3 page tree.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordMoveEventListener
{
    /**
     * Handler for database record operations in the search indexing system.
     *
     * This property stores the RecordHandler service that provides methods for
     * working with database records in the context of search indexing. It is used
     * to determine root page IDs, update records in the indexing queue, and process
     * related records that might be affected by the move operation.
     *
     * @var RecordHandler
     */
    private readonly RecordHandler $recordHandler;

    /**
     * Repository for page-related operations.
     *
     * This property stores the PageRepository service that provides methods for
     * retrieving page information and navigating page hierarchies. It is used to
     * find subpages of a modified page, which is necessary for updating the entire
     * page tree in the search index when a page is modified.
     *
     * @var PageRepository
     */
    private readonly PageRepository $pageRepository;

    /**
     * The current record move event being processed.
     *
     * This property stores the DataHandlerRecordMoveEvent that triggered this listener.
     * It provides access to information about the moved record, including the table name,
     * record UID, target PID, and previous PID. This information is used to determine
     * what actions need to be taken to update the search index.
     *
     * @var DataHandlerRecordMoveEvent
     */
    private DataHandlerRecordMoveEvent $event;

    /**
     * Constructor method for initializing dependencies.
     *
     * @param RecordHandler  $recordHandler  The handler for managing records.
     * @param PageRepository $pageRepository The repository for managing page data.
     *
     * @return void
     */
    public function __construct(
        RecordHandler $recordHandler,
        PageRepository $pageRepository,
    ) {
        $this->recordHandler  = $recordHandler;
        $this->pageRepository = $pageRepository;
    }

    /**
     * Processes the record move event and updates the search index accordingly.
     *
     * This method is automatically called by the event dispatcher when a DataHandlerRecordMoveEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Stores the event for later reference
     * 2. Checks if the record has actually changed location (different target and source PIDs)
     * 3. Determines the root page ID for the moved record to establish the correct indexing context
     * 4. Updates the record in the indexing queue to ensure it will be re-indexed with its new location
     * 5. For content elements, also processes the page that previously contained the element
     *    to ensure that page's index entry is updated to reflect the removal of the content
     *
     * The method includes a TODO note about checking if the record is enabled before adding it
     * to the queue and index, which could be implemented in the future to avoid indexing
     * hidden or otherwise disabled records.
     *
     * @param DataHandlerRecordMoveEvent $event The record move event containing information about the moved record
     *
     * @return void
     */
    public function __invoke(DataHandlerRecordMoveEvent $event): void
    {
        $this->event = $event;

        // Source and target parent ID are the same => Do nothing
        if ($this->event->getTargetPid() === $this->event->getPreviousPid()) {
            return;
        }

        // TODO Check if record is enabled before adding to queue and index

        $pageRecord = $this->pageRepository
            ->getPageRecord(
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        // Determine the root page ID for the event record
        $rootPageId = $this->recordHandler
            ->getRecordRootPageId(
                $pageRecord,
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        $this->recordHandler
            ->updateRecordInQueue(
                $rootPageId,
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        // Update previous page
        if (
            $this->isContentElementUpdate()
            && ($this->event->getPreviousPid() !== null)
        ) {
            $this->recordHandler
                ->processPageOfContentElement(
                    $rootPageId,
                    $this->event->getPreviousPid()
                );
        }
    }

    /**
     * Determines if the moved record is a content element.
     *
     * This helper method checks if the table name of the moved record is 'tt_content',
     * which indicates that the record is a content element. Content elements require
     * special handling when moved, as both the element itself and the pages it was
     * moved from and to need to be updated in the search index.
     *
     * This method is used in the __invoke method to determine whether additional
     * processing is needed for the page that previously contained the content element.
     *
     * @return bool TRUE if the moved record is a content element, FALSE otherwise
     */
    private function isContentElementUpdate(): bool
    {
        return $this->event->getTable() === 'tt_content';
    }
}
