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
 * The file data handler.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class FileHandler
{
    /**
     * @return int<1, max>|false
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
     * Returns the metadata record of the file.
     *
     * @param FileInterface $file
     *
     * @return array<string, int|float|string|null>
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
