<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Repository\CategoryRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileRepository;
use TYPO3\CMS\Core\Resource\File;

use function array_any;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function str_starts_with;
use function trim;

/**
 * Service for managing file collections and checking file memberships.
 *
 * This class provides functionalities to determine whether specific files
 * belong to defined collections. It supports various types of collections,
 * including static collections, folder-based collections, and category-based
 * collections. The class encapsulates the underlying logic for performing
 * these checks using repositories for file and collection data management.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class FileCollectionService
{
    private const string TYPE_STATIC = 'static';

    private const string TYPE_FOLDER = 'folder';

    private const string TYPE_CATEGORY = 'category';

    /**
     * Constructor for the class.
     *
     * Initializes the necessary repositories for managing files and file collections.
     *
     * @param FileCollectionRepository $fileCollectionRepository Repository for handling file collection operations.
     * @param FileRepository           $fileRepository           Repository for handling file-related operations.
     * @param CategoryRepository       $categoryRepository       Repository for handling category-related operations.
     *
     * @return void
     */
    public function __construct(
        private FileCollectionRepository $fileCollectionRepository,
        private FileRepository $fileRepository,
        private CategoryRepository $categoryRepository,
    ) {
    }

    /**
     * Determines if the given file is part of any specified file collections.
     *
     * This method evaluates membership across multiple collection types.
     * It checks static collections, folder-based collections, and
     * category-based collections to determine if the file is included.
     *
     * @param File       $file           The file being checked for membership.
     * @param array<int> $collectionUids Array of collection UIDs to evaluate.
     *
     * @return bool TRUE if the file belongs to any of the specified collections, FALSE otherwise.
     */
    public function isInAnyCollection(File $file, array $collectionUids): bool
    {
        if ($collectionUids === []) {
            return false;
        }

        // Load only the minimal collection data needed for matching logic
        $collectionRows = $this->fileCollectionRepository->getCollectionDataByIds($collectionUids);

        if ($collectionRows === []) {
            return false;
        }

        $fileUid = $file->getUid();

        // Fast-path checks: return TRUE on first successful match
        if ($this->hasStaticCollectionMatch($fileUid, $collectionRows)) {
            return true;
        }

        if ($this->hasFolderCollectionMatch($file, $collectionRows)) {
            return true;
        }

        return $this->hasCategoryCollectionMatchExact($file, $collectionRows);
    }

    /**
     * Determines if a file is part of any static file collection.
     *
     * Static file collections are determined based on type and associated references.
     *
     * @param int                                                                                           $fileUid        The unique identifier of the file.
     * @param list<array{uid: int, type: string, folder_identifier: string, recursive: int, category: int}> $collectionRows The collection rows to analyze, including collection metadata.
     *
     * @return bool TRUE if the file is associated with any static file collection, otherwise FALSE.
     */
    private function hasStaticCollectionMatch(int $fileUid, array $collectionRows): bool
    {
        $staticCollectionUids = $this->getCollectionValuesByType(
            $collectionRows,
            self::TYPE_STATIC,
            'uid'
        );

        if ($staticCollectionUids === []) {
            return false;
        }

        return $this->fileRepository
            ->hasFileReference($fileUid, 'sys_file_collection', $staticCollectionUids);
    }

    /**
     * Checks membership in folder-based file collections.
     *
     * folder_identifier is stored storage-prefixed, e.g.:
     *   "1:/Dokumente/"
     *
     * Matching rules:
     * - recursive = 1 → file identifier must start with folder_identifier
     * - recursive = 0 → file's parent folder must match exactly
     *
     * @param File                                                                                          $file
     * @param list<array{uid: int, type: string, folder_identifier: string, recursive: int, category: int}> $collectionRows
     *
     * @return bool TRUE if any folder collection contains the file.
     */
    private function hasFolderCollectionMatch(File $file, array $collectionRows): bool
    {
        $storage = $file->getStorage();

        if ($storage === null) {
            return false;
        }

        $storageUid = $storage->getUid();

        // Example: "1:/Dokumente/foo.pdf"
        $storagePrefixedFileIdentifier = $storageUid . ':' . $file->getIdentifier();

        // Example: "1:/Dokumente/"
        $storagePrefixedParentFolderIdentifier = $storageUid . ':' . $file->getParentFolder()->getIdentifier();

        return array_any(
            $collectionRows,
            static function (array $collectionRow) use (
                $storagePrefixedFileIdentifier,
                $storagePrefixedParentFolderIdentifier
            ): bool {
                if ($collectionRow['type'] !== self::TYPE_FOLDER) {
                    return false;
                }

                $folderIdentifier = trim($collectionRow['folder_identifier']);
                if ($folderIdentifier === '') {
                    return false;
                }

                $isRecursive = $collectionRow['recursive'] === 1;

                if ($isRecursive) {
                    return str_starts_with(
                        $storagePrefixedFileIdentifier,
                        $folderIdentifier
                    );
                }

                return $storagePrefixedParentFolderIdentifier === $folderIdentifier;
            }
        );
    }

    /**
     * Checks membership in CategoryBasedFileCollections.
     *
     * Important:
     * - Only the exact category configured in the collection is considered.
     * - Child categories are NOT included (TYPO3 core behaviour).
     *
     * @param File                                                                                          $file
     * @param list<array{uid: int, type: string, folder_identifier: string, recursive: int, category: int}> $collectionRows
     *
     * @return bool TRUE if the file's metadata has any of the collection categories.
     */
    private function hasCategoryCollectionMatchExact(File $file, array $collectionRows): bool
    {
        $categoryUids = $this->getCollectionValuesByType(
            $collectionRows,
            self::TYPE_CATEGORY,
            'category'
        );

        if ($categoryUids === []) {
            return false;
        }

        // Resolve sys_file_metadata UID (category relations live there)
        $metadataUid = (int) ($file->getMetaData()->offsetGet('uid') ?? 0);

        if ($metadataUid <= 0) {
            return false;
        }

        return $this->categoryRepository
            ->hasCategoryReference(
                $metadataUid,
                'sys_file_metadata',
                $categoryUids
            );
    }

    /**
     * Helper to extract unique, non-zero UIDs/values from collection rows by type and field.
     *
     * @param list<array{uid: int, type: string, folder_identifier: string, recursive: int, category: int}> $collectionRows
     * @param string                                                                                        $type           The collection type to filter by (e.g. self::TYPE_STATIC)
     * @param string                                                                                        $field          The field to extract (e.g. 'uid' or 'category')
     *
     * @return int[] List of unique, positive integers.
     */
    private function getCollectionValuesByType(array $collectionRows, string $type, string $field): array
    {
        return array_values(
            array_unique(
                array_map(
                    static fn (array $collectionRow): int => (int) $collectionRow[$field],
                    array_filter(
                        $collectionRows,
                        static fn (array $collectionRow): bool => ($collectionRow['type'] === $type) && (($collectionRow[$field] ?? 0) > 0)
                    )
                )
            )
        );
    }
}
