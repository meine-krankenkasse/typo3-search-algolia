<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Event;

/**
 * This event is triggered when a delete command for a record is sent in the TYPO3 system.
 *
 * The event is dispatched by the DataHandlerHook before the command is carried out, and by
 * the listener of the file events before a file is deleted, so the record still exists and
 * the command may still be refused. It provides information about which record is about
 * to be deleted (table name and record UID) to allow event listeners to collect what they
 * need while the record exists. The DataHandlerRecordDeletedEvent announces the record once
 * it is really deleted.
 *
 * The search indexing system collects the context of the record on this event and removes
 * the record from search indices on the DataHandlerRecordDeletedEvent.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class DataHandlerRecordDeleteEvent extends AbstractDataHandlerRecordEvent
{
}
