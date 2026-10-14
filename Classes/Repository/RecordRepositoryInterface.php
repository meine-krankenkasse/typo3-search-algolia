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

    /**
     * Finds the translations of the given records.
     *
     * Only the deleted state and the workspace are restrictions here, so the live
     * translations are returned, hidden ones included.
     *
     * @param string $tableName  The database table name of the records
     * @param int[]  $recordUids The unique identifiers of the records
     *
     * @return int[] The UIDs of the translations, empty for a table without a translation field
     */
    public function findTranslationUids(
        string $tableName,
        array $recordUids,
    ): array;

    /**
     * Finds the records that are stored on the given pages.
     *
     * Only the deleted state and the workspace are restrictions here, so the live
     * records are returned, hidden ones and ones outside of their start and end
     * time included.
     *
     * @param string $tableName The database table name of the records
     * @param int[]  $pageIds   The UIDs of the pages
     *
     * @return int[] The UIDs of the records, empty for a table that does not exist
     */
    public function findUidsByPids(
        string $tableName,
        array $pageIds,
    ): array;

    /**
     * Finds the records of one language that are stored on a page.
     *
     * @param string $tableName   The database table name of the records
     * @param int    $pageId      The UID of the page the records are stored on
     * @param int    $languageUid The UID of the language
     *
     * @return int[] The UIDs of the records, empty for a table that cannot be localized
     */
    public function findUidsByPidAndLanguage(
        string $tableName,
        int $pageId,
        int $languageUid,
    ): array;

    /**
     * Returns those of the given records that are not deleted.
     *
     * @param string $tableName  The database table name of the records
     * @param int[]  $recordUids The unique identifiers of the records
     *
     * @return int[] The UIDs of the records that still exist
     */
    public function findExistingUids(
        string $tableName,
        array $recordUids,
    ): array;

    /**
     * Returns the UIDs of the live records whose field has one of the given values.
     *
     * Hidden and time restricted records are included, deleted records and workspace
     * versions are not. A long list of values is asked for in blocks.
     *
     * @param string             $tableName The database table name of the records
     * @param string             $field     The name of the field to compare, an integer or string column
     * @param int[]              $values    The values to look for
     * @param array<string, int> $equals    Further integer fields that have to equal a value
     *
     * @return int[] The UIDs of the records
     */
    public function findUidsWhere(
        string $tableName,
        string $field,
        array $values,
        array $equals = [],
    ): array;

    /**
     * Returns what DataHandler deletes or moves together with a record without a command of its own.
     *
     * These are the translations of the record, the children of its inline relations and of
     * the relations of its translations, and the translations of those children. Children that
     * DataHandler leaves in place are returned as well. The callers check existence after a
     * delete and decide them again after a move.
     *
     * @param string   $tableName   The database table name of the record
     * @param int      $recordUid   The UID of the record
     * @param string[] $childTables The tables whose children are looked for, the table of the record included
     *
     * @return array<string, int[]> The UIDs of the dependent records by table
     */
    public function findDependentUids(
        string $tableName,
        int $recordUid,
        array $childTables,
    ): array;
}
