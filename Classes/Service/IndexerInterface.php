<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Indexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;

/**
 * The interface that every indexer must implement.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @api
 */
interface IndexerInterface
{
    /**
     * Returns the type of the indexer.
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Returns the table of the indexer.
     *
     * @return string
     */
    public function getTable(): string;

    /**
     * Enqueues the indexer related items. Returns the number of enqueued items.
     *
     * @return int
     */
    public function enqueue(): int;

    /**
     * Returns indexer related query builder constraints.
     *
     * @return string[]
     */
    public function getIndexerConstraints(): array;

    /**
     * Dequeues the indexer related items.
     *
     * @return void
     */
    public function dequeue(): void;

    /**
     * Index a record.
     *
     * @param Indexer $indexer
     * @param array   $record
     *
     * @return bool
     */
    public function indexRecord(Indexer $indexer, array $record): bool;
}
