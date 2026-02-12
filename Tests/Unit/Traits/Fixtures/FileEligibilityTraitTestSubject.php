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
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Test subject for FileEligibilityTrait.
 */
class FileEligibilityTraitTestSubject
{
    use FileEligibilityTrait;

    /**
     * @param string[] $allowedFileExtensions
     */
    public function callIsEligible(FileInterface $file, array $allowedFileExtensions): bool
    {
        return $this->isEligible($file, $allowedFileExtensions);
    }

    /**
     * @param string[] $fileExtensions
     */
    public function callIsExtensionAllowed(FileInterface $file, array $fileExtensions): bool
    {
        return $this->isExtensionAllowed($file, $fileExtensions);
    }

    public function callIsIndexable(FileInterface $file): bool
    {
        return $this->isIndexable($file);
    }
}
