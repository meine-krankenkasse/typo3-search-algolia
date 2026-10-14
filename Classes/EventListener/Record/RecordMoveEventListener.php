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
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;

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
 * - For a record that came from another site, also asks the indexing services of
 *   that site, so that its document is removed from the index of the former site
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
     * The current record move event being processed.
     *
     * This property stores the DataHandlerRecordMoveEvent that triggered this listener.
     * It provides access to information about the moved record, including the table name,
     * record UID, target PID, and previous PID. This information is used to determine
     * what actions need to be taken to update the search index.
     */
    private DataHandlerRecordMoveEvent $event;

    /**
     * Constructor method for initializing dependencies.
     *
     * @param RecordHandler    $recordHandler    The handler for managing records.
     * @param PageRepository   $pageRepository   The repository for managing page data.
     * @param RecordRepository $recordRepository The repository to find the content elements of the pages of a moved tree.
     *
     * @return void
     */
    public function __construct(
        private readonly RecordHandler $recordHandler,
        private readonly PageRepository $pageRepository,
        private readonly RecordRepository $recordRepository,
    ) {
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
     * 4. Updates the record in the indexing queue to ensure it will be re-indexed with its new location,
     *    and removes its document from each search index in which no consulted indexing service
     *    accepts it any longer
     * 5. For a record that came from another site, asks the indexing services of that site
     *    as well, so that its document is removed from the index of the former site
     * 6. For content elements, also processes the page that previously contained the element
     *    to ensure that page's index entry is updated to reflect the removal of the content
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

        $pageRecord = $this->pageRepository
            ->getPageRecord(
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        // Determine the root page ID for the event record
        $rootPageId = $this->findRootPageId($pageRecord);

        $previousPid = $this->event->getPreviousPid();

        // The services of the site that the record came from are consulted
        // together with the ones of the new site, because the services of the
        // new site only decide about the document in the index of the former
        // site when they write to the same index
        $previousRootPageId = ($previousPid !== null)
            ? $this->pageRepository->getRootPageId($previousPid)
            : null;

        $this->recordHandler
            ->updateRecordsInQueue(
                $rootPageId,
                $this->event->getTable(),
                [$this->event->getRecordUid()],
                previousRootPageId: $previousRootPageId
            );

        if ($previousPid === null) {
            return;
        }

        // The subpages and the content elements of a moved page are in another place
        // as well, because their ancestor moved, and there is no command for them. A
        // site root keeps its own site, so its tree does not change with the move.
        if (
            ($this->event->getTable() === 'pages')
            && (((int) ($pageRecord['is_siteroot'] ?? 0)) !== 1)
        ) {
            $this->updateMovedTree($rootPageId, $previousRootPageId);
        }

        if ($this->isContentElementUpdate()) {
            // DataHandler moves the translations of the element, the children of its inline
            // relations and their translations without a command of their own
            $dependentUids = $this->recordRepository
                ->findDependentUids(
                    $this->event->getTable(),
                    $this->event->getRecordUid(),
                    [$this->event->getTable()]
                )[$this->event->getTable()] ?? [];

            $this->recordHandler
                ->updateRecordsInQueue(
                    $rootPageId,
                    $this->event->getTable(),
                    $dependentUids,
                    previousRootPageId: $previousRootPageId
                );

            // Update previous page, with the services of the site it belongs to
            $this->recordHandler
                ->processPageOfContentElement(
                    ($previousRootPageId > 0) ? $previousRootPageId : $rootPageId,
                    $previousPid
                );

            // The page that received the element has a new content element in its document
            $this->recordHandler
                ->processPageOfContentElement($rootPageId, $this->event->getTargetPid());
        }
    }

    /**
     * Determines the root page of the moved record in its new location.
     *
     * DataHandler flushes the cache of the rootlines only after the hooks ran, so
     * the rootline of a moved page can still be the one of its former location.
     * The root of a moved page is therefore taken from the page it was moved to.
     *
     * @param array<string, int|string|null> $pageRecord The moved record
     *
     * @return int The UID of the root page
     */
    private function findRootPageId(array $pageRecord): int
    {
        if (
            ($this->event->getTable() !== 'pages')
            || (((int) ($pageRecord['is_siteroot'] ?? 0)) === 1)
        ) {
            return $this->recordHandler
                ->getRecordRootPageId(
                    $pageRecord,
                    $this->event->getTable(),
                    $this->event->getRecordUid()
                );
        }

        return $this->pageRepository->getRootPageId($this->event->getTargetPid());
    }

    /**
     * Updates the subpages and the content elements of a moved page.
     *
     * The subpages and the content elements are decided like the moved page, each list in
     * one pass, so a document is removed from an index when no service of either site that
     * writes to it accepts the record any longer, and the records that are accepted are
     * queued again for the new location. A site root below the moved page, and everything
     * below it, keeps its own site and is left out.
     *
     * @param int      $rootPageId         The UID of the root page of the new location
     * @param int|null $previousRootPageId The UID of the root page of the former location, 0 or NULL outside of a site
     *
     * @return void
     */
    private function updateMovedTree(
        int $rootPageId,
        ?int $previousRootPageId,
    ): void {
        $recordUid = $this->event->getRecordUid();

        // A site root below the moved page keeps its own site, with everything below it. The
        // rootlines are not used for that, because they can still be the cached ones of the
        // former location.
        $subPageIds = $this->pageRepository
            ->getDescendantPageIds(
                $recordUid,
                forSiteTree: true
            );

        // The subpages are decided in one pass, so the services of the sites are resolved
        // and asked once and not once per subpage
        $this->recordHandler
            ->updateRecordsInQueue(
                $rootPageId,
                'pages',
                $subPageIds,
                previousRootPageId: $previousRootPageId
            );

        $contentElementUids = $this->recordRepository
            ->findUidsByPids('tt_content', [$recordUid, ...$subPageIds]);

        // Content elements are accepted by the page tree a service selects, so the move
        // can take them out of it within a site just like it can take the pages
        $this->recordHandler
            ->updateRecordsInQueue(
                $rootPageId,
                'tt_content',
                $contentElementUids,
                previousRootPageId: $previousRootPageId
            );
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
