<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * The interface that every indexer must implement.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @api
 */
interface IndexerInterface extends SingletonInterface
{
    /**
     * Returns the table of the indexer.
     *
     * @return string
     */
    public function getTable(): string;

    /**
     * Returns an instance with the specified indexing service.
     *
     * @param IndexingService $indexingService
     *
     * @return IndexerInterface
     */
    public function withIndexingService(IndexingService $indexingService): IndexerInterface;

    /**
     * Returns an instance of the indexing service which excludes hidden pages.
     *
     * @param bool $excludeHiddenPages
     *
     * @return IndexerInterface
     */
    public function withExcludeHiddenPages(bool $excludeHiddenPages): IndexerInterface;

    /**
     * Indexes a single record using the configured search engine and indexing service configuration.
     *
     * @param IndexingService      $indexingService
     * @param array<string, mixed> $record
     *
     * @return bool
     */
    public function indexRecord(IndexingService $indexingService, array $record): bool;

    /**
     * Dequeues a single indexing service related item.
     *
     * @param int $recordUid
     *
     * @return IndexerInterface
     */
    public function dequeueOne(int $recordUid): IndexerInterface;

    /**
     * Dequeues the indexing service related items.
     *
     * @return IndexerInterface
     */
    public function dequeueAll(): IndexerInterface;

    /**
     * Enqueues a single indexing service related item. Returns the number of enqueued items (0 or 1).
     *
     * @param int $recordUid
     *
     * @return int
     *
     * @throws Exception
     */
    public function enqueueOne(int $recordUid): int;

    /**
     * Enqueues the indexing service related items. Returns the number of enqueued items.
     *
     * @return int
     *
     * @throws Exception
     */
    public function enqueueAll(): int;
}
