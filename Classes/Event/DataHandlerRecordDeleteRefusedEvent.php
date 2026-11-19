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
 * This event is triggered when a delete command ended without a deletion.
 *
 * The DataHandlerRecordDeleteEvent is dispatched before DataHandler checks the
 * permissions of the user, so the command may still be refused or may change
 * nothing, for example for a record that is deleted already. This event is
 * dispatched once DataHandler has run the command and the record is not
 * deleted. A listener that collected the context of the record for the delete
 * event can discard it, because no DataHandlerRecordDeletedEvent follows.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class DataHandlerRecordDeleteRefusedEvent extends AbstractDataHandlerRecordEvent
{
}
