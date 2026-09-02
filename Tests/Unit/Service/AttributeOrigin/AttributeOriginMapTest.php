<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\AttributeOrigin;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOrigin;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AttributeOriginMap.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AttributeOriginMap::class)]
#[UsesClass(AttributeOrigin::class)]
final class AttributeOriginMapTest extends TestCase
{
    /**
     * Verifies withAttribute() returns a new instance (immutability) and
     * getOrigin() returns the origin assigned via the new instance, not
     * via the original, unmodified one.
     */
    #[Test]
    public function withAttributeIsImmutableAndGetOriginReturnsTheAssignedOrigin(): void
    {
        $original = new AttributeOriginMap();
        $withUid  = $original->withAttribute('uid', AttributeOrigin::Default);

        self::assertNull($original->getOrigin('uid'));
        self::assertSame(AttributeOrigin::Default, $withUid->getOrigin('uid'));
    }

    /**
     * Verifies getOrigin() returns NULL, not an exception or a default
     * value, for an attribute name that was never assigned an origin.
     */
    #[Test]
    public function getOriginReturnsNullForAnUnknownAttribute(): void
    {
        $map = new AttributeOriginMap();

        self::assertNull($map->getOrigin('does_not_exist'));
    }

    /**
     * Verifies getAttributeNames() returns every assigned attribute name,
     * in the order they were added.
     */
    #[Test]
    public function getAttributeNamesReturnsAllAssignedNamesInInsertionOrder(): void
    {
        $map = (new AttributeOriginMap())
            ->withAttribute('uid', AttributeOrigin::Default)
            ->withAttribute('categories', AttributeOrigin::Listener);

        self::assertSame(['uid', 'categories'], $map->getAttributeNames());
    }

    /**
     * Verifies getOriginDetails() carries the detail through for an
     * attribute assigned one, alongside its origin's string value.
     */
    #[Test]
    public function getOriginDetailsCarriesTheDetailThroughWhenProvided(): void
    {
        $map = (new AttributeOriginMap())
            ->withAttribute(
                'title',
                AttributeOrigin::TypoScript,
                'module.tx_typo3searchalgolia.indexer.pages.fields.title',
            );

        self::assertSame(
            [
                'title' => [
                    'origin' => 'typoscript',
                    'detail' => 'module.tx_typo3searchalgolia.indexer.pages.fields.title',
                ],
            ],
            $map->getOriginDetails(),
        );
    }

    /**
     * Verifies getOriginDetails() reports NULL, not an empty string or a
     * missing array key, for an attribute assigned no detail at all.
     */
    #[Test]
    public function getOriginDetailsReportsNullDetailWhenNoneProvided(): void
    {
        $map = (new AttributeOriginMap())
            ->withAttribute('uid', AttributeOrigin::Default);

        self::assertSame(
            [
                'uid' => [
                    'origin' => 'default',
                    'detail' => null,
                ],
            ],
            $map->getOriginDetails(),
        );
    }

    /**
     * Verifies re-assigning the SAME attribute name without a detail
     * clears an already-stored one, rather than leaving it dangling from
     * the earlier assignment. withAttribute() clones and overwrites
     * origins[$name] unconditionally either way, this proves details[$name]
     * is kept in sync with it, not merely additive.
     */
    #[Test]
    public function withAttributeClearsAPreviouslyStoredDetailWhenReassignedWithoutOne(): void
    {
        $map = (new AttributeOriginMap())
            ->withAttribute(
                'title',
                AttributeOrigin::TypoScript,
                'module.tx_typo3searchalgolia.indexer.pages.fields.title',
            )
            ->withAttribute('title', AttributeOrigin::Listener);

        self::assertNull($map->getOriginDetails()['title']['detail']);
    }
}
