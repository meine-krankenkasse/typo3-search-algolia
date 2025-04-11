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
 * This event is triggered if a record is moved.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class DataHandlerRecordMoveEvent
{
    /**
     * @var string
     */
    private string $table;

    /**
     * @var int<1, max>
     */
    private int $recordUid;

    /**
     * The previous parent ID (before moving).
     *
     * @var int|null
     */
    private ?int $previousPid = null;

    /**
     * The newly assigned parent ID (after moving).
     *
     * @var int
     */
    private int $targetPid;

    /**
     * Constructor.
     *
     * @param string      $table     The table currently processing data for
     * @param int<1, max> $recordUid The record uid currently processing data for
     * @param int         $targetPid The ID of the page to which the record was moved. If the value is greater than
     *                               or equal to 0, it refers to the page ID where the record should be inserted
     *                               (as the first element). If it is less than 0, it refers to a UID from the table
     *                               after which it should be inserted.
     */
    public function __construct(string $table, int $recordUid, int $targetPid)
    {
        $this->table     = $table;
        $this->recordUid = $recordUid;
        $this->targetPid = $targetPid;
    }

    /**
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return int<1, max>
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * @return int
     */
    public function getTargetPid(): int
    {
        return $this->targetPid;
    }

    /**
     * @return null|int
     */
    public function getPreviousPid(): ?int
    {
        return $this->previousPid;
    }

    /**
     * @param null|int $previousPid
     *
     * @return DataHandlerRecordMoveEvent
     */
    public function setPreviousPid(?int $previousPid): DataHandlerRecordMoveEvent
    {
        $this->previousPid = $previousPid;
        return $this;
    }
}
