<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use TYPO3\CMS\Core\Resource\AbstractFile;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;

/**
 * Event listener for handling file deletion operations in the search indexing system.
 *
 * This listener responds to AfterFileDeletedEvent events that are dispatched by TYPO3
 * when a file is deleted from a resource storage or driver. It ensures that deleted
 * files are properly removed from the search index by:
 *
 * 1. Checking if the file is actually deleted (using isDeleted())
 * 2. Retrieving the metadata UID for the deleted file using the FileHandler
 * 3. Dispatching a DataHandlerRecordDeleteEvent for the file's metadata record
 *    to trigger the removal process
 *
 * This listener is essential for maintaining the integrity of the search index
 * when files are deleted from the TYPO3 system, ensuring that they are no longer
 * returned in search results after deletion.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileDeletedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Processes the file deleted event and triggers removal of the file's metadata from the search index.
     *
     * This method is automatically called by the event dispatcher when an AfterFileDeletedEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Retrieves the file from the event
     * 2. Checks if the file is already marked as deleted (using isDeleted())
     *    - If it is, returns early as no further action is needed
     * 3. Retrieves the metadata UID for the deleted file using the FileHandler
     * 4. If a valid metadata UID is found, dispatches a DataHandlerRecordDeleteEvent
     *    for the 'sys_file_metadata' table with that UID
     *
     * The dispatched DataHandlerRecordDeleteEvent will be handled by the RecordDeleteEventListener,
     * which will ensure that the file's metadata is removed from both the indexing queue and
     * the search engine index, preventing the deleted file from appearing in search results.
     *
     * If no valid metadata UID is found, the method returns early and no removal is triggered.
     *
     * @param AfterFileDeletedEvent $event The file deleted event containing the deleted file
     *
     * @return void
     */
    public function __invoke(AfterFileDeletedEvent $event): void
    {
        $file = $event->getFile();

        // File already deleted
        if (
            ($file instanceof AbstractFile)
            && $file->isDeleted()
        ) {
            return;
        }

        $metadataUid = $this->fileHandler->getMetadataUid($file);

        if ($metadataUid === false) {
            return;
        }

        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordDeleteEvent(
                    'sys_file_metadata',
                    $metadataUid
                )
            );
    }
}
