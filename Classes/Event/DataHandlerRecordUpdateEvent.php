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
 * This event is triggered when a record is created or updated in the TYPO3 system.
 *
 * The event is dispatched by the DataHandlerHook when a record is created or updated
 * in the TYPO3 backend or through the DataHandler API. It provides information about:
 * - Which record was created or updated (table name and record UID)
 * - What fields were changed (if available)
 *
 * Event listeners can use this information to perform related actions, such as:
 * - Adding or updating the record in search indices
 * - Updating related records that might be affected by the changes
 * - Logging creation or update operations for auditing purposes
 *
 * This event is particularly important for the search indexing system, as it allows
 * for automatic indexing of new or modified records to keep search indices in sync
 * with the TYPO3 database content.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class DataHandlerRecordUpdateEvent
{
    /**
     * Constructor for the DataHandlerRecordUpdateEvent.
     *
     * Initializes a new event instance with the table name and record UID of the
     * created or updated record, as well as the updated field values. This event
     * is typically dispatched by the DataHandlerHook when a record is created or
     * updated in the TYPO3 backend or through the DataHandler API.
     *
     * @param string                    $table     The database table name of the created or updated record
     * @param int<1, max>               $recordUid The unique identifier of the created or updated record
     * @param array<string, int|string> $fields    The updated field values of the record
     * @param bool                      $isNew     TRUE if the record was just created, FALSE otherwise
     */
    public function __construct(
        private string $table,
        private int $recordUid,
        private array $fields = [],
        private bool $isNew = false,
    ) {
    }

    /**
     * Returns the database table name of the created or updated record.
     *
     * This method provides access to the name of the database table that the record
     * belongs to. Event listeners can use this method to retrieve the table name for
     * identifying the type of content that was created or updated and for locating
     * the corresponding document in search indices.
     *
     * @return string The database table name of the created or updated record
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Returns the unique identifier of the created or updated record.
     *
     * This method provides access to the UID of the database record that was created
     * or updated. Event listeners can use this method to retrieve the record UID for
     * locating the corresponding document in search indices for updating.
     *
     * @return int<1, max> The unique identifier of the created or updated record
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * Tells whether the record was just created.
     *
     * A record that was just created normally has no document in a search index,
     * so a listener does not have to look for one to remove. Besides the
     * DataHandler, the handling of a file that was added or copied sets the
     * flag. When a file is added under the name of an existing one with the
     * conflict mode replace through the API, the existing metadata record and
     * its document are kept, and the check is skipped for it as well. Every
     * other dispatcher leaves the flag FALSE, for example the replacement of a
     * file in the backend, which is also the upload with the conflict mode
     * replace.
     *
     * @return bool TRUE if the record was just created, FALSE otherwise
     */
    public function isNew(): bool
    {
        return $this->isNew;
    }

    /**
     * Returns the updated field values of the record.
     *
     * This method provides access to an associative array of field names and their
     * new values for the fields that were changed during the update operation.
     * Event listeners can use this method to determine what specific changes were
     * made to the record and to update search indices accordingly.
     *
     * For newly created records, this may contain all fields of the record.
     * For updated records, it may contain only the fields that were actually changed.
     * The array may be empty if no field information was provided when the event was created.
     *
     * @return array<string, int|string> The updated field values of the record
     */
    public function getFields(): array
    {
        return $this->fields;
    }
}
