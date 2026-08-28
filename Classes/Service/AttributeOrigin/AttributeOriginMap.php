<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin;

use function array_keys;
use function array_map;

/**
 * Immutable map of attribute name to the origin it was classified as.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class AttributeOriginMap
{
    /**
     * @var array<string, AttributeOrigin>
     */
    private array $origins = [];

    /**
     * Returns a new instance with the given attribute assigned the given origin.
     *
     * @param string          $name   The attribute name
     * @param AttributeOrigin $origin The origin to assign
     *
     * @return AttributeOriginMap A new instance carrying the added assignment
     */
    public function withAttribute(string $name, AttributeOrigin $origin): AttributeOriginMap
    {
        $clone                 = clone $this;
        $clone->origins[$name] = $origin;

        return $clone;
    }

    /**
     * Returns the origin assigned to the given attribute, or NULL if none is assigned.
     *
     * @param string $name The attribute name
     *
     * @return AttributeOrigin|null The assigned origin, or NULL if the attribute is unknown
     */
    public function getOrigin(string $name): ?AttributeOrigin
    {
        return $this->origins[$name] ?? null;
    }

    /**
     * Returns all attribute names currently assigned an origin, in insertion order.
     *
     * @return string[] The attribute names
     */
    public function getAttributeNames(): array
    {
        return array_keys($this->origins);
    }

    /**
     * Returns the map as a plain array of attribute name to origin value.
     *
     * Named getOrigins(), not toArray(): TYPO3 Fluid's StandardVariableProvider
     * only resolves an object-path segment via a get{Prop}()/is{Prop}()/
     * has{Prop}() method or a public property, so the Task 5 template's
     * `{section.originMap.origins}` lookup depends on this exact prefix.
     *
     * @return array<string, string> Attribute name to AttributeOrigin::value
     */
    public function getOrigins(): array
    {
        return array_map(
            static fn (AttributeOrigin $origin): string => $origin->value,
            $this->origins,
        );
    }
}
