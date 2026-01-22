<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\PathUtility;

use function is_array;

/**
 * Repository for accessing file information and usages in the TYPO3 database.
 *
 * This repository provides methods for retrieving file-related data from the
 * TYPO3 database. It offers specialized methods for:
 * - Retrieving metadata like filename, path, and MIME type/extension
 * - Finding where files are used within content elements (tt_content)
 *
 * The repository uses direct database queries via TYPO3's ConnectionPool for
 * optimal performance when retrieving file and reference data.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class FileRepository
{
    /**
     * Initializes the repository with the database connection pool.
     *
     * This constructor injects the TYPO3 connection pool that is used
     * throughout the repository for database operations. The connection
     * pool provides access to the database connections for different tables.
     *
     * @param ConnectionPool $connectionPool The TYPO3 database connection pool
     */
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Retrieves file information (name, path, type) for a given metadata UID.
     *
     * This method fetches file metadata (sys_file record) based on a given
     * sys_file_metadata UID. It returns the filename, the directory path,
     * and the file type (MIME type or extension).
     *
     * This information is primarily used for displaying file details in the
     * backend module's indexing queue statistics.
     *
     * @param int $metadataUid The unique identifier of the sys_file_metadata record
     *
     * @return array<string, string> An associative array containing 'name', 'path', and 'type'
     */
    public function findInfo(int $metadataUid): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file');

        $file = $queryBuilder
            ->select(
                'f.name',
                'f.identifier',
                'f.extension',
                'f.mime_type'
            )
            ->from(
                'sys_file',
                'f'
            )
            ->join(
                'f',
                'sys_file_metadata',
                'm',
                'm.file = f.uid'
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'm.uid',
                    $queryBuilder->createNamedParameter(
                        $metadataUid,
                        Connection::PARAM_INT
                    )
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($file)) {
            return [];
        }

        return [
            'name' => (string) $file['name'],
            'path' => PathUtility::dirname((string) $file['identifier']),
            'type' => (string) ($file['mime_type'] ?? $file['extension']),
        ];
    }

    /**
     * Retrieves usages of a file in content elements (tt_content).
     *
     * This method finds all content elements (tt_content records) that reference
     * the file associated with the given sys_file_metadata UID. It searches
     * the sys_file_reference table for matching references.
     *
     * This information is used for displaying file usage details in the
     * backend module's indexing queue statistics.
     *
     * @param int $metadataUid The unique identifier of the sys_file_metadata record
     *
     * @return array<int, array<string, int|string>> A list of associative arrays, each containing the 'uid' of a content element
     */
    public function findUsages(int $metadataUid): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file_reference');

        return $queryBuilder
            ->distinct()
            ->select(
                'c.uid'
            )
            ->from(
                'sys_file_reference',
                'r'
            )
            ->join(
                'r',
                'sys_file_metadata',
                'm',
                'r.uid_local = m.file'
            )
            ->join(
                'r',
                'tt_content',
                'c',
                'r.uid_foreign = c.uid'
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'm.uid',
                    $queryBuilder->createNamedParameter(
                        $metadataUid,
                        Connection::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->eq(
                    'r.tablenames',
                    $queryBuilder->createNamedParameter('tt_content')
                ),
                $queryBuilder->expr()->eq(
                    'r.deleted',
                    $queryBuilder->createNamedParameter(
                        0,
                        Connection::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->eq(
                    'c.deleted',
                    $queryBuilder->createNamedParameter(
                        0,
                        Connection::PARAM_INT
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Retrieves the sys_file UID for a given sys_file_metadata UID.
     *
     * @param int $metadataUid The unique identifier of the sys_file_metadata record
     *
     * @return int|null The UID of the sys_file record, or null if not found
     */
    public function getFileUidByMetadataUid(int $metadataUid): ?int
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file_metadata');

        $result = $queryBuilder
            ->select('file')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $metadataUid,
                        Connection::PARAM_INT
                    )
                )
            )
            ->executeQuery()
            ->fetchOne();

        return $result !== false ? ((int) $result) : null;
    }

    /**
     * Checks if a file has a reference to any of the given UIDs.
     *
     * @param int    $fileUid     UID of the sys_file record
     * @param string $tableName   The table name
     * @param int[]  $foreignUids List of foreign UIDs (uid_foreign)
     *
     * @return bool
     */
    public function hasFileReference(int $fileUid, string $tableName, array $foreignUids): bool
    {
        if ($foreignUids === []) {
            return false;
        }

        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('sys_file_reference');

        $matchingRecord = $queryBuilder
            ->select('uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter($tableName)
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter('files')
                ),
                $queryBuilder->expr()->eq(
                    'uid_local',
                    $queryBuilder->createNamedParameter(
                        $fileUid,
                        Connection::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->in(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter(
                        $foreignUids,
                        ArrayParameterType::INTEGER
                    )
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $matchingRecord !== false;
    }
}
