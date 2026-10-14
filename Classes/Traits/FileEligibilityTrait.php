<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Traits;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception as ResourceException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FolderInterface;

use function in_array;

/**
 * Trait for checking file eligibility for indexing.
 *
 * This trait provides methods to determine if a file is eligible for indexing
 * based on its properties, metadata, and allowed extensions. It is used by
 * both the FileIndexer and the QueueProvider to ensure consistent logic. The
 * permission check for queueing a file by hand is shared by the QueueProvider,
 * which offers the context menu entry, and the controller behind it.
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
            && $this->isIndexable($file)
            && !$this->isInRecycler($file);
    }

    /**
     * Tells whether a file lies in a recycler folder or below one.
     *
     * A file that was moved to a recycler folder is not wanted in the index. The folders
     * are looked at without the permission check of the user, because the parent of the
     * folder of a file mount lies outside of what the user may see, and a folder that
     * cannot be looked at is no recycler. A storage that cannot be browsed has no folders.
     *
     * @param File $file The file to check
     *
     * @return bool True if the file is in a recycler folder
     */
    private function isInRecycler(File $file): bool
    {
        $storage = $file->getStorage();

        if (!$storage->isBrowsable()) {
            return false;
        }

        $evaluatePermissions = $storage->getEvaluatePermissions();

        $storage->setEvaluatePermissions(false);

        try {
            $folder = $file->getParentFolder();

            while ($folder instanceof Folder) {
                if ($folder->getRole() === FolderInterface::ROLE_RECYCLER) {
                    return true;
                }

                $parentFolder = $folder->getParentFolder();

                // The root folder is its own parent
                if ($parentFolder->getIdentifier() === $folder->getIdentifier()) {
                    return false;
                }

                $folder = $parentFolder;
            }
        } catch (ResourceException) {
            // A folder that is gone from the disk is no recycler, the file is judged by the
            // other criteria
            return false;
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }

        return false;
    }

    /**
     * Checks if the backend user may queue the metadata of a file by hand.
     *
     * A file of the fallback storage is never allowed, because the file mounts
     * of a user do not apply to it.
     *
     * @param FileInterface             $file        The file to check
     * @param BackendUserAuthentication $backendUser The backend user to check the permissions of
     *
     * @return bool True if the user may edit the metadata of the file in the default language, false otherwise
     */
    protected function mayEditMetadata(
        FileInterface $file,
        BackendUserAuthentication $backendUser,
    ): bool {
        // The file mounts of a user are not evaluated for the fallback
        // storage, so its files cannot be judged by the action permission
        return ($file instanceof File)
            && !$file->getStorage()->isFallbackStorage()
            && $file->checkActionPermission('editMeta')
            && $backendUser->check('tables_modify', 'sys_file_metadata')
            && $backendUser->checkLanguageAccess(0);
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
