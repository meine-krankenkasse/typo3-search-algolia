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
     * The database table name of the created or updated record.
     *
     * This property contains the name of the database table that the record belongs to
     * (e.g., "pages", "tt_content", "sys_file_metadata"). It is used to identify the
     * type of content that was created or updated and is essential for locating the
     * corresponding document in search indices.
     *
     * @var string
     */
    private string $table;

    /**
     * The unique identifier of the created or updated record.
     *
     * This property contains the UID of the database record that was created or updated.
     * It uniquely identifies the record within its table and is essential for locating
     * the corresponding document in search indices for updating.
     *
     * @var int<1, max>
     */
    private int $recordUid;

    /**
     * The updated field values of the record.
     *
     * This property contains an associative array of field names and their new values
     * for the fields that were changed during the update operation. For newly created
     * records, this may contain all fields of the record. For updated records, it may
     * contain only the fields that were actually changed.
     *
     * This information can be used by event listeners to determine what specific changes
     * were made to the record and to update search indices accordingly.
     *
     * @var array<string, int|string>
     */
    private array $fields;

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
     */
    public function __construct(string $table, int $recordUid, array $fields = [])
    {
        $this->table     = $table;
        $this->recordUid = $recordUid;
        $this->fields    = $fields;
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
