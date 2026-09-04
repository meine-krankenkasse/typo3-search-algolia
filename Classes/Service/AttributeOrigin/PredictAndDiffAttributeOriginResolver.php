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
use function array_search;
use function array_values;
use function in_array;
use function is_string;

/**
 * Classifies document fields by comparing the real, assembled document
 * against a statically predicted baseline (hardcoded defaults + TypoScript
 * mapping), attributing anything not predicted to an event listener.
 *
 * This resolver itself never runs DocumentBuilder::assemble() and never
 * changes DocumentBuilder, it only classifies a Document instance that was
 * already assembled elsewhere (by AttributeOverviewModuleController, via a
 * real, write-free dry run). The predicted baseline is intentionally static
 * (hardcoded default field names plus the TypoScript field mapping) rather
 * than tracked through the listeners themselves, because there is no
 * central registry of which listener touches which field, anything present
 * on the real document but absent from that baseline is, by elimination,
 * attributable to a listener.
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
        $table                 = $document->getIndexer()->getTable();
        $fieldMapping          = $this->typoScriptService->getFieldMappingByType($table);
        $typoScriptTargetNames = array_values($fieldMapping);

        $map = new AttributeOriginMap();

        foreach (array_keys($document->getFields()) as $fieldName) {
            $origin = $this->classify(
                $fieldName,
                $typoScriptTargetNames,
            );
            $detail = $origin === AttributeOrigin::TypoScript
                ? $this->buildTypoScriptPath(
                    $table,
                    $fieldMapping,
                    $fieldName,
                )
                : null;

            $map = $map->withAttribute(
                $fieldName,
                $origin,
                $detail,
            );
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

    /**
     * Builds the exact TypoScript path a TypoScript-origin attribute was
     * mapped through (module.tx_typo3searchalgolia.indexer.<table>.fields.<sourceField>,
     * see AttributeOrigin::TypoScript's own docblock), so the template can
     * name it directly instead of just showing the generic "typoscript"
     * badge.
     *
     * Known, accepted limitation: if a site's own TypoScript configures TWO
     * different source fields on the SAME table to the SAME target
     * attribute name (not the case in this extension's own shipped
     * Configuration/TypoScript/setup.typoscript, where every table's own
     * mapping is one-to-one, but TypoScript field mapping is site-
     * configurable), this method still names the FIRST such source field in
     * $fieldMapping's own (TypoScript-declaration) order. The actual
     * document value, however, comes from DocumentBuilder::
     * addConfiguredFieldsToDocument(), which iterates the DB record in
     * column order and unconditionally overwrites via Document::setField(),
     * so the LAST matching source field in DB-column order is what the
     * exampleValue column actually shows. These two orderings can diverge,
     * making the named path point at a source field other than the one the
     * shown value actually came from. Fixing this precisely would require
     * DocumentBuilder to track, per target field, which source field last
     * won, and propagate that provenance here, a bigger change to the real
     * indexing pipeline for a diagnostic-only, admin-only preview's cosmetic
     * sub-label, not the origin classification itself (still correctly
     * shown as "typoscript" either way). Not attempted, this is a
     * site-misconfiguration edge case (mapping two source fields to one
     * target on the same table is itself unusual), documented here so a
     * future reader isn't surprised.
     *
     * @param string                $table           The database table name
     * @param array<string, string> $fieldMapping    The raw TypoScript field mapping (source field => target attribute name)
     * @param string                $targetAttribute The already-classified TypoScript-origin attribute name
     *
     * @return string|null The TypoScript path, or NULL if $targetAttribute is not actually in $fieldMapping's values
     *                     (defensive only, classify() only returns TypoScript for a name it found there)
     */
    private function buildTypoScriptPath(string $table, array $fieldMapping, string $targetAttribute): ?string
    {
        $sourceField = array_search(
            $targetAttribute,
            $fieldMapping,
            true,
        );

        if (!is_string($sourceField)) {
            return null;
        }

        return 'module.tx_typo3searchalgolia.indexer.' . $table . '.fields.' . $sourceField;
    }
}
