<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap;

use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;

/**
 * Compares attribute-name sets across record types on two independent
 * levels (runtime and config), see
 * docs/superpowers/specs/2026-08-28-attribute-overview-module-design.md
 * "Gap detection" for the rationale for keeping both levels.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class SchemaGapDetector
{
    /**
     * @param array<string, AttributeOriginMap> $originMapsByType Record type name to its AttributeOriginMap
     *
     * @return SchemaGap[]
     */
    public function detectRuntimeGaps(array $originMapsByType): array
    {
        $attributeNamesByType = array_map(
            static fn (AttributeOriginMap $map): array => $map->getAttributeNames(),
            $originMapsByType,
        );

        return $this->diff($attributeNamesByType);
    }

    /**
     * @param array<string, string[]> $fieldMappingTargetsByType Record type name to its TypoScript-mapped target attribute names
     *
     * @return SchemaGap[]
     */
    public function detectConfigGaps(array $fieldMappingTargetsByType): array
    {
        return $this->diff($fieldMappingTargetsByType);
    }

    /**
     * @param array<string, string[]> $attributeNamesByType Record type name to its attribute-name list
     *
     * @return SchemaGap[]
     */
    private function diff(array $attributeNamesByType): array
    {
        $allTypes = array_keys($attributeNamesByType);
        $allNames = array_unique(array_merge(...array_values($attributeNamesByType)));

        $gaps = [];

        foreach ($allNames as $attributeName) {
            $presentOn = [];
            $missingOn = [];

            foreach ($allTypes as $type) {
                if (in_array($attributeName, $attributeNamesByType[$type], true)) {
                    $presentOn[] = $type;
                } else {
                    $missingOn[] = $type;
                }
            }

            if ($missingOn !== []) {
                $gaps[] = new SchemaGap($attributeName, $presentOn, $missingOn);
            }
        }

        return $gaps;
    }
}
