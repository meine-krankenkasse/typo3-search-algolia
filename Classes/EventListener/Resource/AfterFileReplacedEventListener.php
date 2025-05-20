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
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;

/**
 * Event listener for handling file replacement operations in the search indexing system.
 *
 * This listener responds to AfterFileReplacedEvent events that are dispatched by TYPO3
 * when a file is replaced with a new version within a resource storage or driver. It
 * ensures that replaced files are properly updated in the search index by:
 *
 * 1. Retrieving the metadata UID for the replaced file using the FileHandler
 * 2. Dispatching a DataHandlerRecordUpdateEvent for the file's metadata record
 *    to trigger the update process
 *
 * This listener is essential for maintaining the integrity of the search index
 * when files are replaced in the TYPO3 system, ensuring that the search index
 * contains the content and metadata of the new file version rather than the old one.
 * This allows users to find the most current version of files in search results.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileReplacedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Processes the file replaced event and triggers updating of the file's metadata in the search index.
     *
     * This method is automatically called by the event dispatcher when an AfterFileReplacedEvent
     * is dispatched. It performs the following tasks:
     *
     * 1. Retrieves the metadata UID for the replaced file using the FileHandler
     * 2. If a valid metadata UID is found, dispatches a DataHandlerRecordUpdateEvent
     *    for the 'sys_file_metadata' table with that UID
     *
     * The dispatched DataHandlerRecordUpdateEvent will be handled by the RecordUpdateEventListener,
     * which will ensure that the file's metadata is updated in both the indexing queue and
     * the search engine index, reflecting the content of the new file version.
     *
     * When a file is replaced, its content changes but its metadata record in the database
     * remains the same. This event listener ensures that the search index is updated to
     * reflect the content of the new file version, allowing users to find the file based
     * on the content of the new version rather than the old one.
     *
     * If no valid metadata UID is found, the method returns early and no update is triggered.
     *
     * @param AfterFileReplacedEvent $event The file replaced event containing the replaced file
     *
     * @return void
     */
    public function __invoke(AfterFileReplacedEvent $event): void
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
