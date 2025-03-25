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
 * This event is triggered if a record is deleted.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class DataHandlerRecordDeleteEvent
{
    /**
     * @var string
     */
    private string $table;

    /**
     * @var int
     */
    private int $recordUid;

    /**
     * Constructor.
     *
     * @param string $table     The table currently processing data for
     * @param int    $recordUid The record uid currently processing data for, [integer] or [string] (like 'NEW...')
     */
    public function __construct(string $table, int $recordUid)
    {
        $this->table     = $table;
        $this->recordUid = $recordUid;
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return int
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }
}
