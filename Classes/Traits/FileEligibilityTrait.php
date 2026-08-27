<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Traits;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;

/**
 * Trait for checking file eligibility for indexing.
 *
 * This trait provides methods to determine if a file is eligible for indexing
 * based on its properties, metadata, and allowed extensions. It is used by
 * both the FileIndexer and the QueueProvider to ensure consistent logic.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
trait FileEligibilityTrait
{
    /**
     * Checks if a file is eligible for indexing based on its properties and configuration.
     *
     * @param FileInterface $file                  The file to check
     * @param string[]      $allowedFileExtensions Array of allowed file extensions
     *
     * @return bool True if the file is eligible, false otherwise
     */
    protected function isEligible(FileInterface $file, array $allowedFileExtensions): bool
    {
        if (!($file instanceof File)) {
            return false;
        }

        if (!$file->isIndexed() || !$this->isExtensionAllowed($file, $allowedFileExtensions)) {
            return false;
        }

        $metaData = $file->getMetaData()->get();

        if (!isset($metaData['no_search'])) {
            // Right after a file was just added, its (cached) metadata aspect only carries
            // the subset of fields extracted during upload (e.g. width/height/uid), not the
            // full sys_file_metadata row (e.g. no_search). Only in that case, reload a fresh
            // aspect instead of silently rejecting the file for a field that simply hasn't
            // been read yet.
            $metaData = GeneralUtility::makeInstance(MetaDataAspect::class, $file)->get();
        }

        return isset($metaData['uid']) && $this->isIndexable($metaData);
    }

    /**
     * Checks if a file's extension is in the list of allowed extensions.
     *
     * @param FileInterface $file           The file to check
     * @param string[]      $fileExtensions Array of allowed file extensions
     *
     * @return bool True if the file extension is allowed, false otherwise
     */
    protected function isExtensionAllowed(FileInterface $file, array $fileExtensions): bool
    {
        return in_array($file->getExtension(), $fileExtensions, true);
    }

    /**
     * Determines if a file should be included in the search index.
     *
     * @param array<string, int|float|string|null> $metaData The file's complete metadata record
     *
     * @return bool True if the file should be indexed, false otherwise
     */
    protected function isIndexable(array $metaData): bool
    {
        return isset($metaData['no_search'])
            && ((int) $metaData['no_search'] === 0);
    }
}
