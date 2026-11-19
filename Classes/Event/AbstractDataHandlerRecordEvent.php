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
 * The record a DataHandler delete command is about, shared by the events of the
 * delete command.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract readonly class AbstractDataHandlerRecordEvent
{
    /**
     * Constructor for the event.
     *
     * @param string $table     The database table name of the record
     * @param int    $recordUid The unique identifier of the record
     */
    public function __construct(
        private string $table,
        private int $recordUid,
    ) {
    }

    /**
     * Returns the database table name of the record.
     *
     * @return string The database table name of the record
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Returns the unique identifier of the record.
     *
     * @return int The unique identifier of the record
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }
}
