<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;

/**
 * The document ID create event listener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class CreateDefaultDocumentIdEventListener
{
    /**
     * Invoke the event listener.
     *
     * @param CreateUniqueDocumentIdEvent $event
     */
    public function __invoke(CreateUniqueDocumentIdEvent $event): void
    {
        $event->setDocumentId(
            Constants::EXTENSION_NAME . ':' . $event->getTableName() . ':' . $event->getRecordUid()
        );
    }
}
