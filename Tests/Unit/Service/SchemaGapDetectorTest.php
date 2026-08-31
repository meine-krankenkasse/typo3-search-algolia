<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOrigin;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SchemaGap;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SchemaGapDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchemaGapDetector.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(SchemaGapDetector::class)]
#[UsesClass(SchemaGap::class)]
#[UsesClass(AttributeOriginMap::class)]
#[UsesClass(AttributeOrigin::class)]
final class SchemaGapDetectorTest extends TestCase
{
    /**
     * Verifies an attribute present in three of four record types' origin
     * maps is reported as a single gap naming exactly which types have it
     * and which don't.
     */
    #[Test]
    public function detectRuntimeGapsFindsAnAttributeMissingOnOneOfFourTypes(): void
    {
        $withSite = static fn (): AttributeOriginMap => (new AttributeOriginMap())
            ->withAttribute('uid', AttributeOrigin::Default)
            ->withAttribute('site', AttributeOrigin::Listener);

        $withoutSite = static fn (): AttributeOriginMap => (new AttributeOriginMap())
            ->withAttribute('uid', AttributeOrigin::Default);

        $subject = new SchemaGapDetector();
        $gaps    = $subject->detectRuntimeGaps([
            'pages'                     => $withSite(),
            'tt_content'                => $withSite(),
            'sys_file_metadata'         => $withSite(),
            'tx_news_domain_model_news' => $withoutSite(),
        ]);

        self::assertCount(1, $gaps);
        self::assertSame('site', $gaps[0]->getAttributeName());
        self::assertSame(['pages', 'tt_content', 'sys_file_metadata'], $gaps[0]->getPresentOn());
        self::assertSame(['tx_news_domain_model_news'], $gaps[0]->getMissingOn());
    }

    /**
     * Verifies no gap is reported when every compared type has the exact
     * same attribute names.
     */
    #[Test]
    public function detectRuntimeGapsReturnsEmptyWhenEveryTypeHasTheSameAttributes(): void
    {
        $map = static fn (): AttributeOriginMap => (new AttributeOriginMap())
            ->withAttribute('uid', AttributeOrigin::Default);

        $subject = new SchemaGapDetector();
        $gaps    = $subject->detectRuntimeGaps([
            'pages'      => $map(),
            'tt_content' => $map(),
        ]);

        self::assertSame([], $gaps);
    }

    /**
     * Verifies the config-level comparison (TypoScript field-mapping
     * target names, independent of any real record) finds the same class
     * of gap as the runtime level, operating on plain string arrays.
     */
    #[Test]
    public function detectConfigGapsFindsATypoScriptMappingTargetMissingOnOneType(): void
    {
        $subject = new SchemaGapDetector();
        $gaps    = $subject->detectConfigGaps([
            'pages'      => ['title', 'description'],
            'tt_content' => ['title'],
        ]);

        self::assertCount(1, $gaps);
        self::assertSame('description', $gaps[0]->getAttributeName());
        self::assertSame(['pages'], $gaps[0]->getPresentOn());
        self::assertSame(['tt_content'], $gaps[0]->getMissingOn());
    }

    /**
     * Verifies every gap is returned, not just the first one found: two
     * independent attribute names each missing on a different type must
     * both be reported.
     */
    #[Test]
    public function detectConfigGapsReturnsEveryGapNotJustTheFirst(): void
    {
        $subject = new SchemaGapDetector();
        $gaps    = $subject->detectConfigGaps([
            'pages'      => ['title', 'description'],
            'tt_content' => ['title', 'teaser'],
        ]);

        self::assertCount(2, $gaps);

        $gapsByAttributeName = [];

        foreach ($gaps as $gap) {
            $gapsByAttributeName[$gap->getAttributeName()] = $gap;
        }

        self::assertSame(['pages'], $gapsByAttributeName['description']->getPresentOn());
        self::assertSame(['tt_content'], $gapsByAttributeName['description']->getMissingOn());
        self::assertSame(['tt_content'], $gapsByAttributeName['teaser']->getPresentOn());
        self::assertSame(['pages'], $gapsByAttributeName['teaser']->getMissingOn());
    }
}
