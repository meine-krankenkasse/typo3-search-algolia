<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Event;

use function array_unique;
use function array_values;

/**
 * This event is dispatched after a system category was saved, created or
 * deleted, before the records assigned to it are requeued for indexing.
 *
 * It initially holds the records assigned through sys_category_record_mm to
 * the category or one of its subcategories, grouped by table, including the
 * records whose assignment the current save removed. Listeners can add
 * records that reference the category in another way, e.g. through a plain
 * integer column of a "select single" category field, so they are requeued
 * as well.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class CollectCategoryRecordsEvent
{
    /**
     * Constructor.
     *
     * @param int                      $categoryUid The UID of the changed category
     * @param array<string, list<int>> $recordUids  The UIDs of the records to requeue, grouped by table
     */
    public function __construct(
        private readonly int $categoryUid,
        private array $recordUids,
    ) {
    }

    /**
     * Returns the UID of the changed category.
     *
     * @return int The category UID
     */
    public function getCategoryUid(): int
    {
        return $this->categoryUid;
    }

    /**
     * Returns the UIDs of the records to requeue, grouped by table.
     *
     * @return array<string, list<int>> The record UIDs, keyed by table name
     */
    public function getRecordUids(): array
    {
        return $this->recordUids;
    }

    /**
     * Adds records of a table to requeue. Records already collected are not
     * added twice.
     *
     * @param string    $tableName  The table the records belong to
     * @param list<int> $recordUids The UIDs of the records to add
     *
     * @return CollectCategoryRecordsEvent The current event instance for method chaining
     */
    public function addRecordUids(
        string $tableName,
        array $recordUids,
    ): CollectCategoryRecordsEvent {
        if ($recordUids === []) {
            return $this;
        }

        $this->recordUids[$tableName] = array_values(
            array_unique(
                [
                    ...$this->recordUids[$tableName] ?? [],
                    ...$recordUids,
                ],
            ),
        );

        return $this;
    }
}
