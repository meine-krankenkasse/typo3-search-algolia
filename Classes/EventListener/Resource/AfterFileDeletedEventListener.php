<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent;
use TYPO3\CMS\Core\Resource\ProcessedFile;

use function spl_object_id;

/**
 * Event listener for handling file deletion operations in the search indexing system.
 *
 * This listener responds to the events that are dispatched by TYPO3 around the deletion
 * of a file from a resource storage or driver. It ensures that deleted files are
 * properly removed from the search index by:
 *
 * 1. Retrieving the metadata UID of the file before it is deleted, because TYPO3 removes
 *    the metadata record of the file when the deletion is done
 * 2. Dispatching a DataHandlerRecordDeleteEvent for the metadata record at that point,
 *    so that the record listener can collect what it needs while the record exists
 * 3. Dispatching a DataHandlerRecordDeletedEvent once TYPO3 reports the file as deleted,
 *    which makes the record listener remove the record from queue and index
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
     * The metadata UIDs of the files that are about to be deleted, by object ID of the file.
     *
     * TYPO3 hands the same file object to both events of one deletion.
     *
     * @var array<int, int>
     */
    private array $metadataUids = [];

    /**
     * Remembers the metadata record of a file that is about to be deleted.
     *
     * This method is called before TYPO3 deletes the file, while its metadata
     * record still exists. It dispatches a DataHandlerRecordDeleteEvent for the
     * 'sys_file_metadata' table with the metadata UID, so that the context of the
     * record is collected, and remembers the UID for the moment the file is deleted.
     *
     * @param BeforeFileDeletedEvent $event The event announcing the file that is about to be deleted
     *
     * @return void
     */
    public function onBeforeDeleted(BeforeFileDeletedEvent $event): void
    {
        $file = $event->getFile();

        // A record remembered for an earlier deletion of this object that did
        // not finish must not be announced for this one
        unset($this->metadataUids[spl_object_id($file)]);

        // The metadata lookup resolves a processed file to its original file,
        // which is not deleted. TYPO3 deletes processed files whenever the
        // original is added or replaced.
        if ($file instanceof ProcessedFile) {
            return;
        }

        $metadataUid = $this->fileHandler->getMetadataUid($file);

        if ($metadataUid === false) {
            return;
        }

        $this->metadataUids[spl_object_id($file)] = $metadataUid;

        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordDeleteEvent(
                    'sys_file_metadata',
                    $metadataUid
                )
            );
    }

    /**
     * Processes the file deleted event and triggers removal of the file's metadata from the search index.
     *
     * This method is called after TYPO3 deleted the file, or moved it to a recycler
     * folder. For a file whose metadata record was remembered before the deletion it
     * dispatches a DataHandlerRecordDeletedEvent for the 'sys_file_metadata' table,
     * so that the record listener removes the record from both the indexing queue and
     * the search engine index, preventing the deleted file from appearing in search results.
     *
     * A file that was not remembered, because it has no metadata record, is left alone.
     *
     * @param AfterFileDeletedEvent $event The file deleted event containing the deleted file
     *
     * @return void
     */
    public function __invoke(AfterFileDeletedEvent $event): void
    {
        $fileId = spl_object_id($event->getFile());

        if (!isset($this->metadataUids[$fileId])) {
            return;
        }

        $metadataUid = $this->metadataUids[$fileId];

        unset($this->metadataUids[$fileId]);

        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordDeletedEvent(
                    'sys_file_metadata',
                    $metadataUid
                )
            );
    }
}
