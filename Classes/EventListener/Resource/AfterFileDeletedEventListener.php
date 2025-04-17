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
 * This event listener is triggered after a file was deleted from a resource storage or driver.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileDeletedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Invoke the event listener.
     *
     * @param AfterFileDeletedEvent $event
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
