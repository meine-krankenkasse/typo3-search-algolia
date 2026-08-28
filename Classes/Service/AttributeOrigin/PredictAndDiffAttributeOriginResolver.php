<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptServiceInterface;
use Override;

use function array_keys;
use function array_values;
use function in_array;

/**
 * Classifies document fields by comparing the real, assembled document
 * against a statically predicted baseline (hardcoded defaults + TypoScript
 * mapping), attributing anything not predicted to an event listener.
 *
 * This never runs DocumentBuilder::assemble() and never changes
 * DocumentBuilder itself, see docs/superpowers/specs/2026-08-28-attribute-overview-module-design.md
 * "Core mechanism" for the rationale.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class PredictAndDiffAttributeOriginResolver implements AttributeOriginResolverInterface
{
    /**
     * The fixed set of fields DocumentBuilder::assemble() always/conditionally
     * sets before dispatching AfterDocumentAssembledEvent, independent of any
     * TypoScript configuration.
     *
     * @var string[]
     */
    private const array DEFAULT_FIELD_NAMES = [
        'uid',
        'pid',
        'type',
        'indexed',
        'created',
        'changed',
    ];

    /**
     * @param TypoScriptServiceInterface $typoScriptService The TypoScript field-mapping lookup used to predict TypoScript-origin attributes
     */
    public function __construct(
        private TypoScriptServiceInterface $typoScriptService,
    ) {
    }

    /**
     * Classifies every field on the given document by comparing it against
     * the statically predicted baseline (hardcoded defaults + TypoScript
     * mapping); anything present on the document but not predicted is
     * attributed to a listener.
     *
     * @param Document $document The already-assembled document to classify
     *
     * @return AttributeOriginMap The classification result
     */
    #[Override]
    public function resolve(Document $document): AttributeOriginMap
    {
        $typoScriptTargetNames = array_values(
            $this->typoScriptService->getFieldMappingByType(
                $document->getIndexer()->getTable(),
            ),
        );

        $map = new AttributeOriginMap();

        foreach (array_keys($document->getFields()) as $fieldName) {
            $map = $map->withAttribute($fieldName, $this->classify($fieldName, $typoScriptTargetNames));
        }

        return $map;
    }

    /**
     * @param string   $fieldName             The field name to classify
     * @param string[] $typoScriptTargetNames The TypoScript-mapped target attribute names for this record type
     *
     * @return AttributeOrigin The classification
     */
    private function classify(string $fieldName, array $typoScriptTargetNames): AttributeOrigin
    {
        if (in_array($fieldName, self::DEFAULT_FIELD_NAMES, true)) {
            return AttributeOrigin::Default;
        }

        if (in_array($fieldName, $typoScriptTargetNames, true)) {
            return AttributeOrigin::TypoScript;
        }

        return AttributeOrigin::Listener;
    }
}
