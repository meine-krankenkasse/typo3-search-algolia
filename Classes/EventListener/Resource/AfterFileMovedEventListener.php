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
 * This event listener is triggered after a file was moved within a resource storage or driver.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileMovedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Invoke the event listener.
     *
     * @param AfterFileMovedEvent $event
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
