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
 * Event listener for handling record creation and update operations in the search indexing system.
 *
 * This listener responds to DataHandlerRecordUpdateEvent events that are dispatched
 * when records are created or modified in the TYPO3 backend or through the DataHandler API.
 * It ensures that the search index is updated to reflect the changes to the record.
 *
 * The listener performs the following tasks:
 * - Determines the root page ID for the updated record to establish the correct indexing context
 * - Checks if the record is enabled (not hidden or deleted) to decide whether to index it
 * - Updates the record in the indexing queue to ensure it will be re-indexed
 * - For content elements, also processes the page that contains the element
 *   to ensure that page's index entry is updated to reflect the changes to the content
 * - For pages, also processes all content elements on the page and all subpages
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
     * Handler for database record operations in the search indexing system.
     *
     * This property stores the RecordHandler service that provides methods for
     * working with database records in the context of search indexing. It is used
     * to determine root page IDs, update records in the indexing queue, and process
     * related records that might be affected by the update operation.
     *
     * @var RecordHandler
     */
    private readonly RecordHandler $recordHandler;

    /**
     * Repository for accessing generic database records across different tables.
     *
     * This property stores the RecordRepository service that provides methods for
     * retrieving information about database records regardless of their specific table.
     * It is primarily used to find the parent page ID (pid) of content elements,
     * which is needed to update the page's search index entry when a content element
     * is modified.
     *
     * @var RecordRepository
     */
    private readonly RecordRepository $recordRepository;

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
     * The current record update event being processed.
     *
     * This property stores the DataHandlerRecordUpdateEvent that triggered this listener.
     * It provides access to information about the created or modified record, including
     * the table name, record UID, and changed fields. This information is used to determine
     * what actions need to be taken to update the search index.
     *
     * @var DataHandlerRecordUpdateEvent
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
     * @param RecordHandler    $recordHandler    The record handler service for database operations
     * @param RecordRepository $recordRepository The repository for accessing generic database records
     * @param PageRepository   $pageRepository   The repository for page-related operations
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
     * Processes the record update event and updates the search index accordingly.
     *
     * This method is automatically called by the event dispatcher when a DataHandlerRecordUpdateEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Stores the event for later reference
     * 2. Determines the root page ID for the updated record to establish the correct indexing context
     * 3. Checks if the record is enabled (not hidden or deleted) to decide whether to index it
     * 4. Updates the record in the indexing queue to ensure it will be re-indexed with its new content
     * 5. For content elements, also processes the page that contains the element
     *    to ensure that page's index entry is updated to reflect the changes to the content
     * 6. For pages, also processes all content elements on the page and all subpages
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
     * Updates a single record in the indexing queue and search engine index.
     *
     * This method handles the core functionality of updating a record in the search system.
     * It performs two main operations based on whether the record is enabled:
     *
     * 1. Always removes the record from the current search index to prevent stale data
     * 2. If the record is enabled (not hidden or deleted):
     *    - Adds it back to the indexing queue for re-indexing with its updated content
     * 3. If the record is disabled:
     *    - Leaves it out of the queue, effectively removing it from search results
     *
     * The method uses the createIndexerGenerator method from RecordHandler to find all
     * indexing services that are configured for the record's table and root page.
     * This ensures that the record is updated in all relevant search indices, even if
     * multiple indexing services are configured for the same table.
     *
     * @param int  $rootPageId      The root page UID to establish the correct indexing context
     * @param int  $recordUid       The unique identifier of the record to update
     * @param bool $isRecordEnabled TRUE if the record is enabled and should be indexed, FALSE if it should be removed from the index
     *
     * @return void
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
     * Updates multiple records in the indexing queue and search engine index.
     *
     * This method is the batch version of processRecordUpdate(), handling multiple records
     * at once for better performance. It performs the same operations as processRecordUpdate()
     * but for an array of record UIDs:
     *
     * 1. Always removes the records from the current search index to prevent stale data
     * 2. If the records are enabled (not hidden or deleted):
     *    - Adds them back to the indexing queue for re-indexing with their updated content
     * 3. If the records are disabled:
     *    - Leaves them out of the queue, effectively removing them from search results
     *
     * Processing multiple records in a single operation is more efficient than
     * calling processRecordUpdate() repeatedly, especially for the queue operations.
     * This is particularly useful when updating a page tree with many subpages.
     *
     * @param int   $rootPageId      The root page UID to establish the correct indexing context
     * @param int[] $recordUids      Array of unique identifiers for the records to update
     * @param bool  $isRecordEnabled TRUE if the records are enabled and should be indexed, FALSE if they should be removed from the index
     *
     * @return void
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
     * Determines if a record is enabled and should be included in the search index.
     *
     * This method checks various conditions to determine if a record should be indexed:
     * 1. Verifies that the record exists in the database
     * 2. Checks if the record is hidden (using the table's 'disabled' field if defined in TCA)
     * 3. Checks if the record is deleted (using the table's 'delete' field if defined in TCA)
     * 4. For pages and file metadata, checks if the 'no_search' flag is set
     *
     * A record is considered enabled only if it exists, is not hidden, is not deleted,
     * and is not excluded from search. This ensures that only valid, visible content
     * is included in the search index, maintaining consistency with what users can
     * see in the frontend.
     *
     * @param string $tableName The database table name of the record to check
     * @param int    $recordUid The unique identifier of the record to check
     *
     * @return bool TRUE if the record is enabled and should be indexed, FALSE otherwise
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
