<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\DataHandling;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * Utility class for handling file-related operations in the search indexing process.
 *
 * This class provides methods for working with TYPO3 file objects, particularly
 * for retrieving metadata information that is needed for indexing files in search
 * engines. It handles different types of file objects (File, FileReference,
 * ProcessedFile) and extracts the necessary data from them in a consistent way.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class FileHandler
{
    /**
     * Retrieves the unique identifier (UID) of a file's metadata record.
     *
     * This method extracts the metadata UID from a file object, which is needed
     * for indexing operations. The metadata UID is the primary key of the
     * sys_file_metadata table entry associated with the file.
     *
     * The method handles different types of file objects by delegating to
     * getMetadataFromFile() to retrieve the complete metadata array, then
     * extracting and validating the UID value.
     *
     * @param FileInterface $file The file object to get metadata UID from
     *
     * @return int<1, max>|false The metadata UID as a positive integer, or false if no valid metadata UID exists
     */
    public function getMetadataUid(FileInterface $file): int|false
    {
        $metadata = $this->getMetadataFromFile($file);

        if (
            ($metadata !== [])
            && isset($metadata['uid'])
            && (((int) $metadata['uid']) > 0)
        ) {
            return (int) $metadata['uid'];
        }

        return false;
    }

    /**
     * Retrieves the complete metadata record for a file.
     *
     * This method extracts metadata information from different types of file objects:
     * - For regular File objects, it directly accesses the metadata
     * - For FileReference and ProcessedFile objects, it retrieves metadata from the original file
     * - For other file types, it returns an empty array
     *
     * The metadata contains information like title, description, alternative text,
     * and other properties that are useful for search indexing and display.
     *
     * @param FileInterface $file The file object to get metadata from
     *
     * @return array<string, int|float|string|null> The complete metadata array or an empty array if no metadata exists
     */
    public function getMetadataFromFile(FileInterface $file): array
    {
        if ($file instanceof File) {
            return $file->getMetaData()->get();
        }

        if (
            ($file instanceof FileReference)
            || ($file instanceof ProcessedFile)
        ) {
            return $file->getOriginalFile()->getMetaData()->get();
        }

        return [];
    }
}
