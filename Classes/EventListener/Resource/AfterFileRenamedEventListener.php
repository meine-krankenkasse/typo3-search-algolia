<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileRenamedEvent;

/**
 * Event listener for handling file rename operations in the search indexing system.
 *
 * This listener responds to AfterFileRenamedEvent events that are dispatched by TYPO3
 * when a file is renamed within a resource storage or driver. It ensures that renamed
 * files are properly updated in the search index by:
 *
 * 1. Retrieving the metadata UID for the renamed file using the FileHandler
 * 2. Dispatching a DataHandlerRecordUpdateEvent for the file's metadata record
 *    to trigger the update process
 *
 * This listener is essential for maintaining the integrity of the search index
 * when files are renamed in the TYPO3 system, ensuring that their name information
 * is updated in the search index and that they remain searchable under their new name.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileRenamedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Processes the file renamed event and triggers updating of the file's metadata in the search index.
     *
     * This method is automatically called by the event dispatcher when an AfterFileRenamedEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Retrieves the metadata UID for the renamed file using the FileHandler
     * 2. If a valid metadata UID is found, dispatches a DataHandlerRecordUpdateEvent
     *    for the 'sys_file_metadata' table with that UID
     *
     * The dispatched DataHandlerRecordUpdateEvent will be handled by the RecordUpdateEventListener,
     * which will ensure that the file's metadata is updated in both the indexing queue and
     * the search engine index, reflecting its new name.
     *
     * When a file is renamed, its metadata record in the database is automatically updated by TYPO3,
     * but this event listener ensures that the search index is also updated to reflect the new name,
     * allowing users to find the file using its new name in search results.
     *
     * If no valid metadata UID is found, the method returns early and no update is triggered.
     *
     * @param AfterFileRenamedEvent $event The file renamed event containing the renamed file
     *
     * @return void
     */
    public function __invoke(AfterFileRenamedEvent $event): void
    {
        $metadataUid = $this->fileHandler->getMetadataUid($event->getFile());

        if ($metadataUid === false) {
            return;
        }

        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordUpdateEvent(
                    'sys_file_metadata',
                    $metadataUid
                )
            );
    }
}
