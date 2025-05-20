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
use TYPO3\CMS\Core\Resource\Event\AfterFileCopiedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Event listener for handling file copy operations in the search indexing system.
 *
 * This listener responds to AfterFileCopiedEvent events that are dispatched by TYPO3
 * when a file is copied within a resource storage or driver. It ensures that copied
 * files are properly indexed in the search engine by:
 *
 * 1. Verifying that the new file is a valid FileInterface instance
 * 2. Retrieving the metadata UID for the copied file using the FileHandler
 * 3. Dispatching a DataHandlerRecordUpdateEvent for the file's metadata record
 *    to trigger the indexing process
 *
 * This listener is essential for maintaining the integrity of the search index
 * when files are copied in the TYPO3 system, ensuring that the copied files
 * become searchable as soon as they are available.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileCopiedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Processes the file copied event and triggers indexing of the copied file's metadata.
     *
     * This method is automatically called by the event dispatcher when an AfterFileCopiedEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Verifies that the new file is a valid FileInterface instance
     *    - If not, returns early as no further action is possible
     * 2. Retrieves the metadata UID for the copied file using the FileHandler
     * 3. If a valid metadata UID is found, dispatches a DataHandlerRecordUpdateEvent
     *    for the 'sys_file_metadata' table with that UID
     *
     * The dispatched DataHandlerRecordUpdateEvent will be handled by the RecordUpdateEventListener,
     * which will ensure that the copied file's metadata is added to the indexing queue and eventually
     * indexed in the search engine, making the file searchable.
     *
     * If no valid metadata UID is found (which can happen if the file doesn't have metadata yet),
     * the method returns early and no indexing is triggered.
     *
     * @param AfterFileCopiedEvent $event The file copied event containing the original and new file
     *
     * @return void
     */
    public function __invoke(AfterFileCopiedEvent $event): void
    {
        if (!($event->getNewFile() instanceof FileInterface)) {
            return;
        }

        $metadataUid = $this->fileHandler->getMetadataUid($event->getNewFile());

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
