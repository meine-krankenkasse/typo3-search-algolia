<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use RuntimeException;

/**
 * Optional capability of an indexer: looking up which records are in scope
 * for its current indexing service without touching the queue.
 *
 * Kept separate from IndexerInterface so indexers implementing that
 * interface directly keep working unchanged. AbstractIndexer implements
 * both, a custom indexer can add this one to take part in features that
 * need the scope lookup, such as the Attribute Overview backend module.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @api
 */
interface InScopeRecordUidProviderInterface
{
    /**
     * Returns the UIDs of records of this indexer's table that are currently
     * in scope for the current indexing service, i.e. the same set
     * enqueueAll() would queue (when $limit is 0). Read-only, does not touch
     * the queue.
     *
     * @param int $limit Maximum number of UIDs to return (applied as an SQL LIMIT
     *                   where supported), 0 for unbounded, i.e. the full in-scope
     *                   set enqueueAll() would queue. Pass a positive limit when
     *                   only a bounded preview is needed (e.g. the Attribute
     *                   Overview module's per-table representative record)
     *                   instead of materializing the full set.
     *
     * @return int[] The in-scope record UIDs
     *
     * @throws RuntimeException If no indexing service is set
     */
    public function findRecordUidsInScope(int $limit = 0): array;
}
