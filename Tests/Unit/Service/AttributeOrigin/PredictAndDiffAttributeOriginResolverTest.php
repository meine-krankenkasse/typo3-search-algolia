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
     * Verifies every DocumentBuilder-hardcoded standard field is classified
     * as AttributeOrigin::Default, independent of any TypoScript mapping.
     */
    #[Test]
    public function resolveClassifiesHardcodedFieldsAsDefault(): void
    {
        $indexer = self::createStub(IndexerInterface::class);
        $indexer->method('getTable')->willReturn('pages');

        $document = new Document($indexer, []);
        $document
            ->setField('uid', 1)
            ->setField('pid', 0)
            ->setField('type', 'pages')
            ->setField('indexed', 1787900000);

        $typoScriptService = self::createStub(TypoScriptServiceInterface::class);
        $typoScriptService->method('getFieldMappingByType')->willReturn([]);

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
        $indexer = self::createStub(IndexerInterface::class);
        $indexer->method('getTable')->willReturn('tt_content');

        $document = new Document($indexer, []);
        $document
            ->setField('uid', 5)
            ->setField('title', 'Camino Francés');

        $typoScriptService = self::createStub(TypoScriptServiceInterface::class);
        $typoScriptService->method('getFieldMappingByType')->willReturn([
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
        $indexer = self::createStub(IndexerInterface::class);
        $indexer->method('getTable')->willReturn('pages');

        $document = new Document($indexer, []);
        $document
            ->setField('uid', 8)
            ->setField('categories', ['Reisen']);

        $typoScriptService = self::createStub(TypoScriptServiceInterface::class);
        $typoScriptService->method('getFieldMappingByType')->willReturn([]);

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
        $indexer = self::createStub(IndexerInterface::class);
        $indexer->method('getTable')->willReturn('pages');

        $document = new Document($indexer, []);
        $document->setField('type', 'pages');

        $typoScriptService = self::createStub(TypoScriptServiceInterface::class);
        $typoScriptService->method('getFieldMappingByType')->willReturn([
            'doktype' => 'type',
        ]);

        $subject = new PredictAndDiffAttributeOriginResolver($typoScriptService);
        $result  = $subject->resolve($document);

        self::assertSame(AttributeOrigin::Default, $result->getOrigin('type'));
    }
}
