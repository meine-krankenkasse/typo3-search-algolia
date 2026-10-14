<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Traits\Fixtures;

use MeineKrankenkasse\Typo3SearchAlgolia\Traits\FileEligibilityTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Test subject for FileEligibilityTrait.
 */
class FileEligibilityTraitTestSubject
{
    use FileEligibilityTrait;

    /**
     * Calls isEligible().
     *
     * @param FileInterface $file                  The file to check
     * @param string[]      $allowedFileExtensions The allowed file extensions
     *
     * @return bool
     */
    public function callIsEligible(FileInterface $file, array $allowedFileExtensions): bool
    {
        return $this->isEligible($file, $allowedFileExtensions);
    }

    /**
     * Calls isExtensionAllowed().
     *
     * @param FileInterface $file           The file to check
     * @param string[]      $fileExtensions The allowed file extensions
     *
     * @return bool
     */
    public function callIsExtensionAllowed(FileInterface $file, array $fileExtensions): bool
    {
        return $this->isExtensionAllowed($file, $fileExtensions);
    }

    /**
     * Calls isIndexable().
     *
     * @param FileInterface $file The file to check
     *
     * @return bool
     */
    public function callIsIndexable(FileInterface $file): bool
    {
        return $this->isIndexable($file);
    }

    /**
     * Calls mayEditMetadata().
     *
     * @param FileInterface             $file        The file to check
     * @param BackendUserAuthentication $backendUser The backend user to check the permissions of
     *
     * @return bool
     */
    public function callMayEditMetadata(
        FileInterface $file,
        BackendUserAuthentication $backendUser,
    ): bool {
        return $this->mayEditMetadata($file, $backendUser);
    }
}
