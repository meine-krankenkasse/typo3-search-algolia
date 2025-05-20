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
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;

/**
 * Event listener for handling file addition operations in the search indexing system.
 *
 * This listener responds to AfterFileAddedEvent events that are dispatched by TYPO3
 * when a file is added to a resource storage or driver. It ensures that newly added
 * files are properly indexed in the search engine by:
 *
 * 1. Retrieving the metadata UID for the added file using the FileHandler
 * 2. Dispatching a DataHandlerRecordUpdateEvent for the file's metadata record
 *    to trigger the indexing process
 *
 * This listener is essential for maintaining the integrity of the search index
 * when new files are uploaded or otherwise added to the TYPO3 system, ensuring
 * that they become searchable as soon as they are available.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileAddedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Processes the file added event and triggers indexing of the file's metadata.
     *
     * This method is automatically called by the event dispatcher when an AfterFileAddedEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Retrieves the metadata UID for the added file using the FileHandler
     * 2. If a valid metadata UID is found, dispatches a DataHandlerRecordUpdateEvent
     *    for the 'sys_file_metadata' table with that UID
     *
     * The dispatched DataHandlerRecordUpdateEvent will be handled by the RecordUpdateEventListener,
     * which will ensure that the file's metadata is added to the indexing queue and eventually
     * indexed in the search engine, making the file searchable.
     *
     * If no valid metadata UID is found (which can happen if the file doesn't have metadata yet),
     * the method returns early and no indexing is triggered.
     *
     * @param AfterFileAddedEvent $event The file added event containing the added file
     *
     * @return void
     */
    public function __invoke(AfterFileAddedEvent $event): void
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
