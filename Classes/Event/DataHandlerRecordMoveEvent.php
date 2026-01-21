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
 * This event is triggered when a record is moved to a different location in the TYPO3 system.
 *
 * The event is dispatched by the DataHandlerHook when a record is moved in the TYPO3
 * backend or through the DataHandler API. It provides information about:
 * - Which record was moved (table name and record UID)
 * - Where the record was moved to (target PID)
 * - Where the record was moved from (previous PID, if available)
 *
 * Event listeners can use this information to perform related actions, such as:
 * - Updating the record in search indices to reflect its new location
 * - Updating related records that might be affected by the move
 * - Logging move operations for auditing purposes
 *
 * This event is particularly important for the search indexing system, as it allows
 * for automatic updating of moved records in search indices to keep them in sync
 * with the TYPO3 database structure.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class DataHandlerRecordMoveEvent
{
    /**
     * The previous parent ID (before moving).
     *
     * This property contains the ID of the page from which the record was moved.
     * It is initially null and can be set using the setPreviousPid() method.
     * This information is useful for understanding the record's original location
     * and for updating related records or search indices accordingly.
     */
    private ?int $previousPid = null;

    /**
     * Constructor for the DataHandlerRecordMoveEvent.
     *
     * Initializes a new event instance with the table name and record UID of the
     * moved record, as well as the target PID to which the record was moved.
     * The previous PID is initially null and can be set later using the setPreviousPid() method.
     *
     * This event is typically dispatched by the DataHandlerHook when a record is moved
     * in the TYPO3 backend or through the DataHandler API.
     *
     * @param string      $table     The database table name of the moved record
     * @param int<1, max> $recordUid The unique identifier of the moved record
     * @param int         $targetPid The ID of the page to which the record was moved. If the value is greater than
     *                               or equal to 0, it refers to the page ID where the record was inserted
     *                               (as the first element). If it is less than 0, it refers to a UID from the table
     *                               after which it was inserted.
     */
    public function __construct(
        private readonly string $table,
        private readonly int $recordUid,
        private readonly int $targetPid,
    ) {
    }

    /**
     * Returns the database table name of the moved record.
     *
     * This method provides access to the name of the database table that the moved
     * record belongs to. Event listeners can use this method to retrieve the table name
     * for identifying the type of content that was moved and for locating the
     * corresponding document in search indices.
     *
     * @return string The database table name of the moved record
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Returns the unique identifier of the moved record.
     *
     * This method provides access to the UID of the database record that was moved.
     * Event listeners can use this method to retrieve the record UID for locating
     * the corresponding document in search indices for updating.
     *
     * @return int<1, max> The unique identifier of the moved record
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * Returns the newly assigned parent ID (after moving).
     *
     * This method provides access to the ID of the page to which the record was moved.
     * Event listeners can use this method to retrieve the target location of the record
     * for updating search indices or related records accordingly.
     *
     * If the value is greater than or equal to 0, it refers to the page ID where
     * the record was inserted. If it is less than 0, it refers to a UID from the
     * table after which the record was inserted.
     *
     * @return int The ID of the page to which the record was moved
     */
    public function getTargetPid(): int
    {
        return $this->targetPid;
    }

    /**
     * Returns the previous parent ID (before moving).
     *
     * This method provides access to the ID of the page from which the record was moved.
     * Event listeners can use this method to retrieve the original location of the record
     * for updating search indices or related records accordingly.
     *
     * The value may be null if the previous PID has not been set using the setPreviousPid() method.
     *
     * @return int|null The ID of the page from which the record was moved, or null if not set
     */
    public function getPreviousPid(): ?int
    {
        return $this->previousPid;
    }

    /**
     * Sets the previous parent ID (before moving).
     *
     * This method allows event dispatchers or listeners to set the ID of the page
     * from which the record was moved. This information is useful for understanding
     * the record's original location and for updating related records or search
     * indices accordingly.
     *
     * The method returns the event instance to allow for method chaining in event
     * dispatchers or listeners.
     *
     * @param int|null $previousPid The ID of the page from which the record was moved, or null to unset
     *
     * @return DataHandlerRecordMoveEvent The current event instance for method chaining
     */
    public function setPreviousPid(?int $previousPid): DataHandlerRecordMoveEvent
    {
        $this->previousPid = $previousPid;

        return $this;
    }
}
