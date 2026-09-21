<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Command\Fixtures;

use ArrayIterator;
use Override;
use RuntimeException;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

use function array_values;
use function count;

/**
 * Minimal QueryResultInterface test double backed by a plain array.
 *
 * Extbase repositories return a lazy QueryResultInterface, not an array, so
 * command tests that iterate over repository results need a real Iterator
 * implementation rather than a partial mock of every Iterator method.
 *
 * @template TValue of object
 *
 * @implements QueryResultInterface<int, TValue>
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class ArrayQueryResult implements QueryResultInterface
{
    /**
     * @var ArrayIterator<int, TValue>
     */
    private ArrayIterator $iterator;

    /**
     * @param list<TValue> $items
     */
    public function __construct(
        private array $items,
    ) {
        $this->iterator = new ArrayIterator($this->items);
    }

    /**
     * Not implemented, this test double has no underlying query to attach.
     */
    #[Override]
    public function setQuery(QueryInterface $query): void
    {
    }

    /**
     * Not implemented, this test double has no underlying query to return.
     */
    #[Override]
    public function getQuery()
    {
        throw new RuntimeException('Not implemented in this test double', 1758444001);
    }

    /**
     * Returns the first item, or null when the backing array is empty.
     */
    #[Override]
    public function getFirst()
    {
        return $this->items[0] ?? null;
    }

    /**
     * Returns all items as a plain, re-indexed array.
     */
    #[Override]
    public function toArray()
    {
        return array_values($this->items);
    }

    /**
     * Returns the number of items in the backing array.
     */
    #[Override]
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Returns the item at the iterator's current position.
     */
    #[Override]
    public function current(): mixed
    {
        return $this->iterator->current();
    }

    /**
     * Advances the iterator to the next item.
     */
    #[Override]
    public function next(): void
    {
        $this->iterator->next();
    }

    /**
     * Returns the key at the iterator's current position.
     */
    #[Override]
    public function key(): mixed
    {
        return $this->iterator->key();
    }

    /**
     * Returns whether the iterator's current position is valid.
     */
    #[Override]
    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    /**
     * Resets the iterator to the first item.
     */
    #[Override]
    public function rewind(): void
    {
        $this->iterator->rewind();
    }

    /**
     * Returns whether the given offset exists in the backing array.
     */
    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Returns the item at the given offset, or null if it doesn't exist.
     */
    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Not implemented, this test double is read-only.
     */
    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RuntimeException('This test double is read-only', 1758444002);
    }

    /**
     * Not implemented, this test double is read-only.
     */
    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new RuntimeException('This test double is read-only', 1758444003);
    }
}
