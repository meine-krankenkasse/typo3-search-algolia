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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;

/**
 * Event listener for handling record deletion operations in the search indexing system.
 *
 * This listener responds to DataHandlerRecordDeleteEvent events that are dispatched
 * when records are deleted in the TYPO3 backend or through the DataHandler API.
 * It ensures that deleted records are removed from both the indexing queue and
 * the search engine index to maintain consistency between the TYPO3 database
 * and the search index.
 *
 * The listener performs the following tasks:
 * - Determines the root page ID for the deleted record to establish the correct indexing context
 * - Removes the record from both the indexing queue and the search engine index
 * - For content elements, also processes the page that contained the element
 *   to ensure that page's index entry is updated to reflect the removal of the content
 * - For pages, also removes all content elements on that page from both the queue and index
 *   to ensure that orphaned content doesn't remain in the search index
 *
 * This listener is essential for maintaining the integrity of the search index
 * when content is deleted from the TYPO3 system.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordDeleteEventListener
{
    /**
     * Handler for database record operations in the search indexing system.
     *
     * This property stores the RecordHandler service that provides methods for
     * working with database records in the context of search indexing. It is used
     * to determine root page IDs, remove records from the indexing queue and search
     * engine index, and process related records that might be affected by the deletion.
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
     * is deleted.
     *
     * @var RecordRepository
     */
    private readonly RecordRepository $recordRepository;

    /**
     * The current record delete event being processed.
     *
     * This property stores the DataHandlerRecordDeleteEvent that triggered this listener.
     * It provides access to information about the deleted record, including the table name
     * and record UID. This information is used to determine what actions need to be taken
     * to update the search index.
     *
     * @var DataHandlerRecordDeleteEvent
     */
    private DataHandlerRecordDeleteEvent $event;

    /**
     * Initializes the event listener with required dependencies.
     *
     * This constructor injects the services needed for handling record deletion operations:
     * - The RecordHandler service provides methods for working with database records
     *   in the context of search indexing, including determining root page IDs,
     *   removing records from the queue and index, and processing related records.
     * - The RecordRepository service provides methods for retrieving information
     *   about database records, particularly for finding the parent page ID of
     *   content elements that are being deleted.
     *
     * @param RecordHandler    $recordHandler    The record handler service for database operations
     * @param RecordRepository $recordRepository The repository for accessing generic database records
     */
    public function __construct(
        RecordHandler $recordHandler,
        RecordRepository $recordRepository,
    ) {
        $this->recordHandler    = $recordHandler;
        $this->recordRepository = $recordRepository;
    }

    /**
     * Processes the record delete event and updates the search index accordingly.
     *
     * This method is automatically called by the event dispatcher when a DataHandlerRecordDeleteEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Stores the event for later reference
     * 2. Determines the root page ID for the deleted record to establish the correct indexing context
     * 3. Removes the record from both the indexing queue and the search engine index
     * 4. For content elements, also processes the page that contained the element
     *    to ensure that page's index entry is updated to reflect the removal of the content
     * 5. For pages, also removes all content elements on that page from both the queue and index
     *    to ensure that orphaned content doesn't remain in the search index
     *
     * The method handles different types of records differently:
     * - Regular records are simply removed from the queue and index
     * - Content elements trigger an update of their parent page's index entry
     * - Pages trigger the removal of all their content elements from the queue and index
     *
     * @param DataHandlerRecordDeleteEvent $event The record delete event containing information about the deleted record
     *
     * @return void
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
     * Removes the deleted record from both the indexing queue and the search engine index.
     *
     * This method handles the core functionality of removing a deleted record from the
     * search system. It:
     *
     * 1. Creates a generator that yields all applicable indexer and indexing service pairs
     *    for the deleted record's table and root page ID
     * 2. Iterates through each pair and uses the RecordHandler to delete the record
     *    from both the indexing queue and the search engine index
     *
     * The method uses the createIndexerGenerator method from RecordHandler to find all
     * indexing services that are configured for the deleted record's table and root page.
     * This ensures that the record is removed from all relevant search indices, even if
     * multiple indexing services are configured for the same table.
     *
     * @param int $rootPageId The root page UID to establish the correct indexing context
     *
     * @return void
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
     * Determines if the deleted record is a content element.
     *
     * This helper method checks if the table name of the deleted record is 'tt_content',
     * which indicates that the record is a content element. Content elements require
     * special handling when deleted, as the page that contained the element needs to
     * be updated in the search index to reflect the removal of the content.
     *
     * This method is used in the __invoke method to determine whether additional
     * processing is needed for the page that contained the deleted content element.
     *
     * @return bool TRUE if the deleted record is a content element, FALSE otherwise
     */
    private function isContentElementUpdate(): bool
    {
        return $this->event->getTable() === 'tt_content';
    }

    /**
     * Determines if the deleted record is a page.
     *
     * This helper method checks if the table name of the deleted record is 'pages',
     * which indicates that the record is a page. Pages require special handling when
     * deleted, as all content elements on the page need to be removed from both the
     * indexing queue and the search engine index to prevent orphaned content from
     * remaining in the search index.
     *
     * This method is used in the __invoke method to determine whether additional
     * processing is needed to clean up content elements that were on the deleted page.
     *
     * @return bool TRUE if the deleted record is a page, FALSE otherwise
     */
    private function isPageUpdate(): bool
    {
        return $this->event->getTable() === 'pages';
    }
}
