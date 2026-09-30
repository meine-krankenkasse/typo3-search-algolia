<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use Override;

/**
 * Test double for a custom indexer that implements IndexerInterface
 * directly, with exactly the method set IndexerInterface had in 3.0.0, and
 * not InScopeRecordUidProviderInterface. Every call is delegated to a real,
 * container-resolved indexer instance.
 *
 * Used to prove AttributeOverviewModuleController::buildTableAttributes()
 * reports such an indexer as STATUS_SCOPE_NOT_SUPPORTED instead of
 * failing. Since every method carries #[Override], this class also stops
 * loading as soon as IndexerInterface gains or loses a method, which pins
 * the interface to its 3.0.0 method set.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class ScopelessIndexer implements IndexerInterface
{
    /**
     * @param IndexerInterface $realIndexer The real indexer every call is delegated to
     */
    public function __construct(
        private IndexerInterface $realIndexer,
    ) {
    }

    /**
     * Returns the real indexer's table.
     *
     * @return string The database table name
     */
    #[Override]
    public function getTable(): string
    {
        return $this->realIndexer->getTable();
    }

    /**
     * Returns a new ScopelessIndexer around the real indexer's clone for the
     * given indexing service.
     *
     * @param IndexingService $indexingService The indexing service configuration to use
     *
     * @return ScopelessIndexer The wrapped clone
     */
    #[Override]
    public function withIndexingService(IndexingService $indexingService): ScopelessIndexer
    {
        return new self($this->realIndexer->withIndexingService($indexingService));
    }

    /**
     * Returns a new ScopelessIndexer around the real indexer's clone for the
     * given hidden pages setting.
     *
     * @param bool $excludeHiddenPages Whether to exclude hidden pages from indexing
     *
     * @return ScopelessIndexer The wrapped clone
     */
    #[Override]
    public function withExcludeHiddenPages(bool $excludeHiddenPages): ScopelessIndexer
    {
        return new self($this->realIndexer->withExcludeHiddenPages($excludeHiddenPages));
    }

    /**
     * Delegates to the real indexer, see IndexerInterface::indexRecord().
     *
     * @return bool The real indexer's result
     */
    #[Override]
    public function indexRecord(IndexingService $indexingService, array $record): bool
    {
        return $this->realIndexer->indexRecord(
            $indexingService,
            $record,
        );
    }

    /**
     * Delegates to the real indexer.
     *
     * @param int $recordUid The record UID to remove from the queue
     *
     * @return IndexerInterface The real indexer
     */
    #[Override]
    public function dequeueOne(int $recordUid): IndexerInterface
    {
        return $this->realIndexer->dequeueOne($recordUid);
    }

    /**
     * Delegates to the real indexer.
     *
     * @param int[] $recordUids The record UIDs to remove from the queue
     *
     * @return IndexerInterface The real indexer
     */
    #[Override]
    public function dequeueMultiple(array $recordUids): IndexerInterface
    {
        return $this->realIndexer->dequeueMultiple($recordUids);
    }

    /**
     * Delegates to the real indexer.
     *
     * @return IndexerInterface The real indexer
     */
    #[Override]
    public function dequeueAll(): IndexerInterface
    {
        return $this->realIndexer->dequeueAll();
    }

    /**
     * Delegates to the real indexer.
     *
     * @param int $recordUid The record UID to add to the queue
     *
     * @return int The real indexer's result
     */
    #[Override]
    public function enqueueOne(int $recordUid): int
    {
        return $this->realIndexer->enqueueOne($recordUid);
    }

    /**
     * Delegates to the real indexer.
     *
     * @param int[] $recordUids The record UIDs to add to the queue
     *
     * @return int The real indexer's result
     */
    #[Override]
    public function enqueueMultiple(array $recordUids): int
    {
        return $this->realIndexer->enqueueMultiple($recordUids);
    }

    /**
     * Delegates to the real indexer.
     *
     * @return int The real indexer's result
     */
    #[Override]
    public function enqueueAll(): int
    {
        return $this->realIndexer->enqueueAll();
    }
}
