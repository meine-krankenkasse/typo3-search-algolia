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
 * Interface for accessing generic database records across different tables.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface RecordRepositoryInterface
{
    /**
     * Finds the parent page ID (pid) for any record in the TYPO3 database.
     *
     * @param string $tableName The database table name of the record
     * @param int    $recordUid The unique identifier of the record
     *
     * @return int|false The parent page ID (pid) of the record, or false if the record doesn't exist
     */
    public function findPid(string $tableName, int $recordUid): int|false;
}
