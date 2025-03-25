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
 * This event is triggered if a record is created or updated.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class DataHandlerRecordUpdateEvent
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
     * @var array<string, int|string>
     */
    private array $fields;

    /**
     * Constructor.
     *
     * @param string                    $table     The table currently processing data for
     * @param int<1, max>               $recordUid The record uid currently processing data for, [integer] or [string] (like 'NEW...')
     * @param array<string, int|string> $fields    The field array of a record
     */
    public function __construct(string $table, int $recordUid, array $fields)
    {
        $this->table     = $table;
        $this->recordUid = $recordUid;
        $this->fields    = $fields;
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
     * @return array<string, int|string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }
}
