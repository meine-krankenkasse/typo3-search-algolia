<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandlerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepositoryInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Utility\PublicationWindow;

/**
 * Event listener for handling record creation and update operations in the search indexing system.
 *
 * This listener responds to DataHandlerRecordUpdateEvent events that are dispatched
 * when records are created or modified in the TYPO3 backend or through the DataHandler API.
 * It ensures that the search index is updated to reflect the changes to the record.
 *
 * The listener performs the following tasks:
 * - Determines the root page ID for the updated record to establish the correct indexing context
 * - Checks if the record is enabled (not hidden, deleted, excluded from the search or outside
 *   of its publication window) to decide whether to index it
 * - Updates the record in the indexing queue to ensure it will be re-indexed
 * - For content elements, also processes the page that contains the element
 *   to ensure that page's index entry is updated to reflect the changes to the content
 * - For pages, also processes the content elements on the page and, when the state they
 *   inherit changed, its subpages
 *   to ensure that the entire page tree is properly indexed
 *
 * This listener is essential for maintaining the integrity of the search index
 * when content is created or modified in the TYPO3 system.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordUpdateEventListener
{
    /**
     * The current record update event being processed.
     *
     * This property stores the DataHandlerRecordUpdateEvent that triggered this listener.
     * It provides access to information about the created or modified record, including
     * the table name, record UID, and changed fields. This information is used to determine
     * what actions need to be taken to update the search index.
     */
    private DataHandlerRecordUpdateEvent $event;

    /**
     * Initializes the event listener with required dependencies.
     *
     * This constructor injects the services needed for handling record update operations:
     * - The RecordHandler service provides methods for working with database records
     *   in the context of search indexing, including determining root page IDs,
     *   updating records in the queue and index, and processing related records.
     * - The RecordRepository service provides methods for retrieving information
     *   about database records, particularly for finding the parent page ID of
     *   content elements that are being modified.
     * - The PageRepository service provides methods for retrieving page information
     *   and navigating page hierarchies, which is necessary for updating the entire
     *   page tree when a page is modified.
     *
     * @param RecordHandlerInterface    $recordHandler    The record handler service for database operations
     * @param RecordRepositoryInterface $recordRepository The repository for accessing generic database records
     * @param PageRepositoryInterface   $pageRepository   The repository for page-related operations
     */
    public function __construct(
        private readonly RecordHandlerInterface $recordHandler,
        private readonly RecordRepositoryInterface $recordRepository,
        private readonly PageRepositoryInterface $pageRepository,
    ) {
    }

    /**
     * Processes the record update event and updates the search index accordingly.
     *
     * This method is automatically called by the event dispatcher when a DataHandlerRecordUpdateEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Stores the event for later reference
     * 2. Determines the root page ID for the updated record to establish the correct indexing context
     * 3. Checks if the record is enabled (not hidden, deleted, excluded from the search or
     *    outside of its publication window) to decide whether to index it
     * 4. Updates the record in the indexing queue to ensure it will be re-indexed with its new content
     * 5. For content elements, also processes the page that contains the element
     *    to ensure that page's index entry is updated to reflect the changes to the content
     * 6. For pages, also processes the content elements on the page and, when the state
     *    they inherit changed, its subpages
     *    to ensure that the entire page tree is properly indexed
     *
     * The method handles different types of records differently:
     * - Regular records are simply updated in the queue and index
     * - Content elements trigger an update of their parent page's index entry
     * - Pages trigger updates of all their content elements and subpages
     *
     * @param DataHandlerRecordUpdateEvent $event The record update event containing information about the created or modified record
     *
     * @return void
     */
    public function __invoke(DataHandlerRecordUpdateEvent $event): void
    {
        $this->event = $event;

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

        $isRecordEnabled = $this->isRecordEnabled(
            $pageRecord,
            $this->event->getTable(),
        );

        // Update record at queue and index
        $this->processRecordUpdate(
            $rootPageId,
            [$this->event->getRecordUid()],
            $isRecordEnabled,
            !$this->event->isNew()
        );

        // Update the page if required
        if ($this->isContentElementUpdate()) {
            // Only a page indexing service that includes the content elements queues the page again
            $pageId = $this->recordRepository
                ->findPid(
                    ContentIndexer::TABLE,
                    $this->event->getRecordUid()
                );

            // Process page update
            if ($pageId !== false) {
                $this->recordHandler
                    ->processPageOfContentElement($rootPageId, $pageId);
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

            $subtreeEnabled = $this->decideSubtree(
                $this->event->getTable(),
                $this->event->getRecordUid(),
                $this->event->getFields()
            );

            if ($subtreeEnabled !== null) {
                // A page that is hidden and extends that state hides its subpages, so the walk
                // includes the subpages that are hidden themselves and removes everything below
                $subPageIds = $this->pageRepository
                    ->getPageIdsRecursive(
                        [
                            $this->event->getRecordUid(),
                        ],
                        Constants::MAX_PAGE_TREE_DEPTH,
                        false,
                        $subtreeEnabled
                    );

                if ($subPageIds !== []) {
                    $this->processRecordUpdate(
                        $rootPageId,
                        $subPageIds,
                        $subtreeEnabled,
                        false
                    );

                    // The content elements of all subpages in one call. An element of a page
                    // that is not accepted any more is left out by the indexer
                    $this->recordHandler
                        ->processContentElementsOfPage(
                            $this->event->getRecordUid(),
                            !$subtreeEnabled,
                            $this->recordRepository->findUidsByPids('tt_content', $subPageIds)
                        );
                }
            }
        }
    }

    /**
     * Decides whether the subpages of a saved page are decided with it, and in which direction.
     *
     * The state that a page hides its subpages is the one of a page that is hidden, or not yet
     * or no longer published, and has Extend to subpages enabled. Only such a page takes its
     * subpages with it, and a page that is hidden or outside of its window without that leaves
     * them alone. The subtree is decided when one of the fields hidden, starttime, endtime or
     * extendToSubpages is part of the save, because the state changed. It is also decided for a
     * save that names no field at all, as the publish of a workspace change does, because a
     * page that is shown again does not say so. Such a save is decided for a page that extends
     * its state or is hidden, so that the publish which switched the extension off on a page
     * that stays hidden queues the subpages again.
     *
     * @param string                    $tableName     The name of the database table containing the record
     * @param int                       $recordUid     The unique identifier of the record
     * @param array<string, int|string> $updatedFields The fields that were updated, with their new values
     *
     * @return bool|null NULL if the subpages are not decided, TRUE if they are enabled, FALSE if the page hides them
     */
    private function decideSubtree(
        string $tableName,
        int $recordUid,
        array $updatedFields = [],
    ): ?bool {
        $record = $this->pageRepository
            ->getPageRecord(
                $tableName,
                $recordUid,
                'hidden, starttime, endtime, extendToSubpages',
                false,
            );

        if ($record === []) {
            return null;
        }

        // A page that has not started or has ended hides its subpages like a hidden one
        $hidden  = PublicationWindow::isHiddenOrOutside($record);
        $extends = ((int) ($record['extendToSubpages'] ?? 0)) === 1;

        $fieldsNamed = isset($updatedFields['hidden'])
            || isset($updatedFields['starttime'])
            || isset($updatedFields['endtime'])
            || isset($updatedFields['extendToSubpages']);

        // A save that names no field is the publish of a workspace change. It is decided for a
        // page that hides or has hidden its subpages, which also covers the publish that
        // switched the extension off on a page that stays hidden
        if (
            !$fieldsNamed
            && !(($updatedFields === []) && ($extends || $hidden))
        ) {
            return null;
        }

        // A save that leaves the extension untouched on a page that does not extend decides no subpages
        if (
            !$extends
            && ($updatedFields !== [])
            && !isset($updatedFields['extendToSubpages'])
        ) {
            return null;
        }

        return !($hidden && $extends);
    }

    /**
     * Updates records of the saved table in the indexing queue and the search engine index.
     *
     * 1. If the records are enabled (not hidden, deleted, excluded from the search or outside
     *    of its publication window):
     *    - Removes them from the indexing queue and adds them back for re-indexing with
     *      their updated content, see RecordHandlerInterface::updateRecordsInQueue(). That
     *      method also removes the documents from each search index in which no consulted
     *      indexer accepts a record, unless the caller asks for no removal.
     * 2. If the records are disabled:
     *    - Removes them from the indexing queue and their documents from the search index.
     *
     * The indexing services that are configured for the table and the root page of the
     * records are found by the record handler, so the records are updated in all relevant
     * search indices, even if several indexing services are configured for the same table.
     *
     * @param int   $rootPageId              The root page UID to establish the correct indexing context
     * @param int[] $recordUids              The unique identifiers of the records to update
     * @param bool  $isRecordEnabled         TRUE if the records are enabled and should be indexed, FALSE if they should be removed from the index
     * @param bool  $removeRejectedDocuments Whether the documents of enabled records that no indexer accepts are removed, FALSE for a record that was just created and for the subpages of a saved page
     *
     * @return void
     */
    private function processRecordUpdate(
        int $rootPageId,
        array $recordUids,
        bool $isRecordEnabled,
        bool $removeRejectedDocuments,
    ): void {
        if ($isRecordEnabled) {
            $this->recordHandler
                ->updateRecordsInQueue(
                    $rootPageId,
                    $this->event->getTable(),
                    $recordUids,
                    $removeRejectedDocuments
                );

            return;
        }

        $this->recordHandler
            ->removeRecords(
                $rootPageId,
                $this->event->getTable(),
                $recordUids
            );
    }

    /**
     * Determines if a record is enabled based on specific conditions.
     *
     * @param array<string, int|string|null> $record    The record data to evaluate.
     * @param string                         $tableName The name of the database table the record belongs to.
     *
     * @return bool True if the record is enabled, false otherwise.
     */
    private function isRecordEnabled(array $record, string $tableName): bool
    {
        return !(
            ($record === [])
            || (isset($GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns']['disabled'])
                && ($record[$GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns']['disabled']] !== 0))
            || (isset($GLOBALS['TCA'][$tableName]['ctrl']['delete'])
                && ($record[$GLOBALS['TCA'][$tableName]['ctrl']['delete']] !== 0))
            // Record is excluded from the search
            || ((($tableName === 'pages') || ($tableName === 'sys_file_metadata'))
                && ($record['no_search'] !== 0))
            // The indexer does not accept a page outside of its publication window
            || (($tableName === 'pages') && PublicationWindow::isOutside($record))
        );
    }

    /**
     * Determines if the updated record is a content element.
     *
     * This helper method checks if the table name of the updated record is 'tt_content',
     * which indicates that the record is a content element. Content elements require
     * special handling when updated, as the page that contains the element needs to
     * be updated in the search index to reflect the changes to the content.
     *
     * This method is used in the __invoke method to determine whether additional
     * processing is needed for the page that contains the updated content element.
     *
     * @return bool TRUE if the updated record is a content element, FALSE otherwise
     */
    private function isContentElementUpdate(): bool
    {
        return $this->event->getTable() === 'tt_content';
    }

    /**
     * Determines if the updated record is a page.
     *
     * This helper method checks if the table name of the updated record is 'pages',
     * which indicates that the record is a page. Pages require special handling when
     * updated, as all content elements on the page and all subpages need to be
     * processed to ensure that the entire page tree is properly indexed.
     *
     * This method is used in the __invoke method to determine whether additional
     * processing is needed for content elements and subpages when a page is updated.
     * This is particularly important for maintaining the integrity of the search index
     * when page properties that affect visibility or access are changed.
     *
     * @return bool TRUE if the updated record is a page, FALSE otherwise
     */
    private function isPageUpdate(): bool
    {
        return $this->event->getTable() === 'pages';
    }
}
