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
 * This event listener is triggered after a file was replaced within a resource storage or driver.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AfterFileReplacedEventListener extends AbstractAfterFileEventListener
{
    /**
     * Invoke the event listener.
     *
     * @param AfterFileReplacedEvent $event
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
