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
 * This event is triggered when a record is deleted in the TYPO3 system.
 *
 * The event is dispatched by the DataHandlerHook when a record is deleted in the TYPO3
 * backend or through the DataHandler API. It provides information about which record
 * was deleted (table name and record UID) to allow event listeners to perform
 * related actions, such as:
 * - Removing the record from search indices
 * - Cleaning up related data in other systems
 * - Logging deletion operations for auditing purposes
 *
 * This event is particularly important for the search indexing system, as it allows
 * for automatic removal of deleted records from search indices to keep them in sync
 * with the TYPO3 database.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class DataHandlerRecordDeleteEvent
{
    /**
     * Constructor for the DataHandlerRecordDeleteEvent.
     *
     * Initializes a new event instance with the table name and record UID of the
     * deleted record. This event is typically dispatched by the DataHandlerHook
     * when a record is deleted in the TYPO3 backend or through the DataHandler API.
     *
     * @param string $table     The database table name of the deleted record
     * @param int    $recordUid The unique identifier of the deleted record
     */
    public function __construct(
        private string $table,
        private int $recordUid,
    ) {
    }

    /**
     * Returns the database table name of the deleted record.
     *
     * This method provides access to the name of the database table that the deleted
     * record belonged to. Event listeners can use this method to retrieve the table name
     * for identifying the type of content that was deleted and for locating the
     * corresponding document in search indices.
     *
     * @return string The database table name of the deleted record
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Returns the unique identifier of the deleted record.
     *
     * This method provides access to the UID of the database record that was deleted.
     * Event listeners can use this method to retrieve the record UID for locating
     * the corresponding document in search indices for removal.
     *
     * @return int The unique identifier of the deleted record
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }
}
