<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMovedEvent;

/**
 * Event listener for handling file move operations in the search indexing system.
 *
 * This listener responds to AfterFileMovedEvent events that are dispatched by TYPO3
 * when a file is moved within a resource storage or driver. It ensures that moved
 * files are properly updated in the search index by:
 *
 * 1. Retrieving the metadata UID for the moved file using the FileHandler
 * 2. Dispatching a DataHandlerRecordMoveEvent for the file's metadata record
 *    to trigger the update process
 *
 * This listener is essential for maintaining the integrity of the search index
 * when files are moved in the TYPO3 system, ensuring that their location information
 * is updated in the search index and that they remain searchable at their new location.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileMovedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Processes the file moved event and triggers updating of the file's metadata in the search index.
     *
     * This method is automatically called by the event dispatcher when an AfterFileMovedEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Retrieves the metadata UID for the moved file using the FileHandler
     * 2. If a valid metadata UID is found, dispatches a DataHandlerRecordMoveEvent
     *    for the 'sys_file_metadata' table with that UID
     *
     * The dispatched DataHandlerRecordMoveEvent will be handled by the RecordMoveEventListener,
     * which will ensure that the file's metadata is updated in both the indexing queue and
     * the search engine index, reflecting its new location.
     *
     * The target PID is set to 0 in the DataHandlerRecordMoveEvent because file metadata records
     * don't have a hierarchical structure like pages, so the actual target location is determined
     * by the file system path rather than a page ID.
     *
     * If no valid metadata UID is found, the method returns early and no update is triggered.
     *
     * @param AfterFileMovedEvent $event The file moved event containing the moved file
     *
     * @return void
     */
    public function __invoke(AfterFileMovedEvent $event): void
    {
        $metadataUid = $this->fileHandler->getMetadataUid($event->getFile());

        if ($metadataUid === false) {
            return;
        }

        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordMoveEvent(
                    'sys_file_metadata',
                    $metadataUid,
                    0
                )
            );
    }
}
