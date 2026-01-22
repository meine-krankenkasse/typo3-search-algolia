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

        return ($file->isIndexed() === true)
            && $this->isExtensionAllowed($file, $allowedFileExtensions)
            && $file->getMetaData()->offsetExists('uid')
            && $this->isIndexable($file);
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
     * @param FileInterface $file The file to check
     *
     * @return bool True if the file should be indexed, false otherwise
     */
    protected function isIndexable(FileInterface $file): bool
    {
        return $file->hasProperty('no_search')
            && ((int) $file->getProperty('no_search') === 0);
    }
}
