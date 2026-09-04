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
     * @var array<string, string>
     */
    private array $details = [];

    /**
     * Returns a new instance with the given attribute assigned the given
     * origin and, optionally, a human-readable detail naming the exact
     * source (e.g. the TypoScript path a TypoScript-origin attribute was
     * mapped through).
     *
     * @param string          $name   The attribute name
     * @param AttributeOrigin $origin The origin to assign
     * @param string|null     $detail A human-readable detail naming the exact source, if known
     *
     * @return AttributeOriginMap A new instance carrying the added assignment
     */
    public function withAttribute(string $name, AttributeOrigin $origin, ?string $detail = null): AttributeOriginMap
    {
        $clone                 = clone $this;
        $clone->origins[$name] = $origin;

        if ($detail !== null) {
            $clone->details[$name] = $detail;
        } else {
            unset($clone->details[$name]);
        }

        return $clone;
    }

    /**
     * Returns the origin assigned to the given attribute, or NULL if none is assigned.
     *
     * No production caller reads this directly either (only getOriginDetails()
     * does, for the template), but unlike a former sibling accessor removed as
     * dead code, this one is the load-bearing assertion mechanism for
     * PredictAndDiffAttributeOriginResolverTest's entire classification
     * suite: comparing against the AttributeOrigin enum directly, rather
     * than against getOriginDetails()'s string-typed 'origin' key, is what
     * makes those assertions type-safe.
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
     * No production caller reads this directly either, but it is this
     * class's own unit test's most direct way to assert withAttribute()'s
     * insertion-order contract, independent of origin values or detail
     * strings.
     *
     * @return string[] The attribute names
     */
    public function getAttributeNames(): array
    {
        return array_keys($this->origins);
    }

    /**
     * Returns the map as a plain array of attribute name to a
     * {origin, detail} pair, for the template to render the origin's
     * exact source (e.g. a TypoScript path) alongside the badge without
     * needing a Fluid dynamic-key lookup into a second, separately keyed
     * array.
     *
     * @return array<string, array{origin: string, detail: string|null}> Attribute name to its {origin, detail} pair
     */
    public function getOriginDetails(): array
    {
        $result = [];

        foreach ($this->origins as $name => $origin) {
            $result[$name] = [
                'origin' => $origin->value,
                'detail' => $this->details[$name] ?? null,
            ];
        }

        return $result;
    }
}
