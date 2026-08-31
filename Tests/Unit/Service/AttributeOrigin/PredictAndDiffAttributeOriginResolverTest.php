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
     * Verifies a field present on the real document but not in either the
     * hardcoded default set or the TypoScript mapping is classified as
     * AttributeOrigin::Listener — the collective, un-attributed bucket.
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
