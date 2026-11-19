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
 * This event is triggered when a record was deleted.
 *
 * The DataHandlerRecordDeleteEvent is dispatched before DataHandler checks the
 * permissions of the user, so the record may still be there when it is handled.
 * This event is dispatched afterwards and only for a record that was live before
 * the command and is deleted now. For a file that is deleted through the file
 * list it is dispatched once the file is deleted, for the metadata record that
 * the DataHandlerRecordDeleteEvent announced before.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class DataHandlerRecordDeletedEvent extends AbstractDataHandlerRecordEvent
{
}
