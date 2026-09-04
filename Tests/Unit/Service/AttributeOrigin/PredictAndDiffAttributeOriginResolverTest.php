<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\AttributeOrigin;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOrigin;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\PredictAndDiffAttributeOriginResolver;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PredictAndDiffAttributeOriginResolver.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(PredictAndDiffAttributeOriginResolver::class)]
#[UsesClass(Document::class)]
#[UsesClass(AttributeOriginMap::class)]
#[UsesClass(AttributeOrigin::class)]
final class PredictAndDiffAttributeOriginResolverTest extends TestCase
{
    /**
     * Creates an IndexerInterface stub whose getTable() reports the given
     * table name, standing in for the real indexer a resolved Document
     * would otherwise be built with.
     */
    private function createIndexerStub(string $table): IndexerInterface
    {
        $indexer = self::createStub(IndexerInterface::class);
        $indexer->method('getTable')->willReturn($table);

        return $indexer;
    }

    /**
     * Creates a TypoScriptServiceInterface stub whose getFieldMappingByType()
     * returns the given TypoScript field mapping, standing in for the real
     * TypoScript configuration resolution.
     *
     * @param array<string, string> $mapping
     */
    private function createTypoScriptServiceStub(array $mapping = []): TypoScriptServiceInterface
    {
        $typoScriptService = self::createStub(TypoScriptServiceInterface::class);
        $typoScriptService->method('getFieldMappingByType')->willReturn($mapping);

        return $typoScriptService;
    }

    /**
     * Verifies every DocumentBuilder-hardcoded standard field is classified
     * as AttributeOrigin::Default, independent of any TypoScript mapping.
     */
    #[Test]
    public function resolveClassifiesHardcodedFieldsAsDefault(): void
    {
        $indexer = $this->createIndexerStub('pages');

        $document = new Document($indexer, []);
        $document
            ->setField('uid', 1)
            ->setField('pid', 0)
            ->setField('type', 'pages')
            ->setField('indexed', 1787900000);

        $typoScriptService = $this->createTypoScriptServiceStub();

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame(AttributeOrigin::Default, $result->getOrigin('uid'));
        self::assertSame(AttributeOrigin::Default, $result->getOrigin('pid'));
        self::assertSame(AttributeOrigin::Default, $result->getOrigin('type'));
        self::assertSame(AttributeOrigin::Default, $result->getOrigin('indexed'));
    }

    /**
     * Verifies a field whose name matches a TypoScript field-mapping
     * target is classified as AttributeOrigin::TypoScript.
     */
    #[Test]
    public function resolveClassifiesTypoScriptMappedFieldsAsTypoScript(): void
    {
        $indexer = $this->createIndexerStub('tt_content');

        $document = new Document($indexer, []);
        $document
            ->setField('uid', 5)
            ->setField('title', 'Camino Francés');

        $typoScriptService = $this->createTypoScriptServiceStub([
            'header' => 'title',
        ]);

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame(AttributeOrigin::TypoScript, $result->getOrigin('title'));
    }

    /**
     * Verifies a TypoScript-origin attribute's detail names the exact
     * TypoScript path it was mapped through, source field included, not
     * just the generic "typoscript" classification alone.
     */
    #[Test]
    public function resolveSetsTheExactTypoScriptPathAsTheDetailForATypoScriptOriginAttribute(): void
    {
        $indexer = $this->createIndexerStub('tt_content');

        $document = new Document($indexer, []);
        $document->setField('title', 'Camino Francés');

        $typoScriptService = $this->createTypoScriptServiceStub([
            'header' => 'title',
        ]);

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame(
            'module.tx_typo3searchalgolia.indexer.tt_content.fields.header',
            $result->getOriginDetails()['title']['detail'],
        );
    }

    /**
     * Pins buildTypoScriptPath()'s documented, accepted limitation for the
     * degenerate case where TWO source fields on the same table map to the
     * SAME target attribute name (not reachable via this extension's own
     * shipped Configuration/TypoScript/setup.typoscript, where every
     * table's mapping is one-to-one, but TypoScript field mapping is
     * site-configurable): the detail names the FIRST such source field in
     * $fieldMapping's own declaration order ('header' here, declared before
     * 'alt_header'), regardless of which source field the actually-rendered
     * value came from (DocumentBuilder::addConfiguredFieldsToDocument()
     * iterates the DB record in column order and overwrites, so the ACTUAL
     * value can come from either field depending on column order, which
     * this unit test - operating on an already-assembled Document - cannot
     * influence). See that method's own docblock for the full rationale.
     */
    #[Test]
    public function resolveNamesTheFirstDeclaredSourceFieldWhenTwoSourceFieldsMapToTheSameTarget(): void
    {
        $indexer = $this->createIndexerStub('tt_content');

        $document = new Document($indexer, []);
        $document->setField('title', 'Camino Francés');

        $typoScriptService = $this->createTypoScriptServiceStub([
            'header'     => 'title',
            'alt_header' => 'title',
        ]);

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame(
            'module.tx_typo3searchalgolia.indexer.tt_content.fields.header',
            $result->getOriginDetails()['title']['detail'],
        );
    }

    /**
     * Verifies a Default-origin attribute carries no detail at all (NULL,
     * not an empty string), the detail is exclusively a TypoScript-origin
     * concept. The stub's mapping deliberately targets 'uid' itself (a
     * degenerate config, also covered by
     * resolveClassifiesADefaultFieldNameAsDefaultEvenWhenAlsoTypoScriptMapped()
     * above): classify() still returns Default first (DEFAULT_FIELD_NAMES
     * is checked before the TypoScript-target check), but resolve()'s own
     * origin-gate must be what suppresses the detail here, not merely an
     * empty mapping making buildTypoScriptPath() independently return
     * NULL on its own, which a non-discriminating version of this test
     * (an empty stub mapping) would not be able to tell apart.
     */
    #[Test]
    public function resolveSetsNoDetailForADefaultOriginAttribute(): void
    {
        $indexer = $this->createIndexerStub('pages');

        $document = new Document($indexer, []);
        $document->setField('uid', 1);

        $typoScriptService = $this->createTypoScriptServiceStub([
            'some_ts_source_field' => 'uid',
        ]);

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertNull($result->getOriginDetails()['uid']['detail']);
    }

    /**
     * Verifies a field present on the real document but not in either the
     * hardcoded default set or the TypoScript mapping is classified as
     * AttributeOrigin::Listener, the collective, un-attributed bucket.
     */
    #[Test]
    public function resolveClassifiesUnpredictedFieldsAsListener(): void
    {
        $indexer = $this->createIndexerStub('pages');

        $document = new Document($indexer, []);
        $document
            ->setField('uid', 8)
            ->setField('categories', ['Reisen']);

        $typoScriptService = $this->createTypoScriptServiceStub();

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame(AttributeOrigin::Default, $result->getOrigin('uid'));
        self::assertSame(AttributeOrigin::Listener, $result->getOrigin('categories'));
    }

    /**
     * A TypoScript mapping targeting a hardcoded default field name is a
     * degenerate config, but must not crash or misclassify: default wins.
     */
    #[Test]
    public function resolveClassifiesADefaultFieldNameAsDefaultEvenWhenAlsoTypoScriptMapped(): void
    {
        $indexer = $this->createIndexerStub('pages');

        $document = new Document($indexer, []);
        $document->setField('type', 'pages');

        $typoScriptService = $this->createTypoScriptServiceStub([
            'doktype' => 'type',
        ]);

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame(AttributeOrigin::Default, $result->getOrigin('type'));
    }

    /**
     * A Document with no fields set at all (the degenerate edge case, e.g.
     * a selected record that could not be fetched) must not crash and must
     * simply classify nothing, an empty AttributeOriginMap.
     */
    #[Test]
    public function resolveReturnsAnEmptyMapForADocumentWithNoFields(): void
    {
        $indexer = $this->createIndexerStub('pages');

        $document = new Document($indexer, []);

        $typoScriptService = $this->createTypoScriptServiceStub();

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame([], $result->getAttributeNames());
    }
}
