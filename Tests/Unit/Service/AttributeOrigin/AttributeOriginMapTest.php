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
     * Verifies getOrigins() returns a plain array mapping each attribute
     * name to its origin's string value, for consumption by the Fluid
     * template (see Task 5).
     */
    #[Test]
    public function getOriginsReturnsAttributeNameToOriginValueMapping(): void
    {
        $map = (new AttributeOriginMap())
            ->withAttribute('uid', AttributeOrigin::Default)
            ->withAttribute('site', AttributeOrigin::TypoScript);

        self::assertSame(
            [
                'uid'  => 'default',
                'site' => 'typoscript',
            ],
            $map->getOrigins(),
        );
    }
}
