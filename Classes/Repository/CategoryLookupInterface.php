<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

/**
 * Interface for looking up category records.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface CategoryLookupInterface
{
    /**
     * Returns the system categories assigned to a record.
     *
     * @param string $tableName
     * @param int    $uid
     * @param string $fieldName The sys_category_record_mm fieldname to filter by
     *
     * @return array<array-key, array<string, int|string|null>>
     */
    public function findAssignedToRecord(string $tableName, int $uid, string $fieldName = 'categories'): array;

    /**
     * Returns a single system category by its UID.
     *
     * @param int $uid The UID of the category to find
     *
     * @return array<string, int|string|null>|false The category record, or false if not found
     */
    public function findByUid(int $uid): array|false;

    /**
     * Checks if a record in the specified table has a relationship to any of the given category UIDs.
     *
     * @param int    $uid          The UID of the record to check for category associations
     * @param string $tableName    The name of the table to which the record belongs
     * @param int[]  $categoryUids An array of category UIDs to match against
     * @param string $fieldName    The sys_category_record_mm fieldname to filter by
     *
     * @return bool True if a category reference exists for the given criteria, false otherwise
     */
    public function hasCategoryReference(int $uid, string $tableName, array $categoryUids, string $fieldName = 'categories'): bool;
}
