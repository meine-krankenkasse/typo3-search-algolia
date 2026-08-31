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
 * One attribute that is present for some record types and missing for others.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class SchemaGap
{
    /**
     * @param string   $attributeName The attribute name
     * @param string[] $presentOn     Record type names that have this attribute
     * @param string[] $missingOn     Record type names that don't have this attribute
     */
    public function __construct(
        private string $attributeName,
        private array $presentOn,
        private array $missingOn,
    ) {
    }

    /**
     * Returns the name of the attribute this gap describes.
     *
     * @return string The attribute name
     */
    public function getAttributeName(): string
    {
        return $this->attributeName;
    }

    /**
     * Returns the record type names that carry this attribute.
     *
     * @return string[] Record type names that have this attribute
     */
    public function getPresentOn(): array
    {
        return $this->presentOn;
    }

    /**
     * Returns the record type names missing this attribute.
     *
     * @return string[] Record type names that don't have this attribute
     */
    public function getMissingOn(): array
    {
        return $this->missingOn;
    }
}
