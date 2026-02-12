<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

/**
 * Interface for accessing TypoScript configuration values.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface TypoScriptServiceInterface
{
    /**
     * Returns the complete TypoScript configuration of the extension.
     *
     * @return array<string, array<string, array<string, string|array<string, string>>>> The processed TypoScript configuration
     */
    public function getTypoScriptConfiguration(): array;

    /**
     * Returns the field mapping for a specific indexer type.
     *
     * @param string $indexerType
     *
     * @return string[]
     */
    public function getFieldMappingByType(string $indexerType): array;

    /**
     * Returns the file extensions allowed for indexing.
     *
     * @return string[] Array of allowed file extensions
     */
    public function getAllowedFileExtensions(): array;
}
