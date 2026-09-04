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

use function count;

/**
 * Test double for IndexerInterface that delegates every call to a real,
 * container-resolved indexer instance, except that
 * findRecordUidsInScope($limit) additionally records the exact $limit
 * argument it was called with (and the number of UIDs actually returned)
 * onto a LimitCapture shared across every with*() clone.
 *
 * Used to prove AttributeOverviewModuleController::buildTableAttributes()
 * genuinely threads its own SCOPE_RECORD_LIMIT constant through to
 * IndexerInterface::findRecordUidsInScope(), as opposed to a dropped,
 * zeroed, or accidentally hardcoded-wrong argument - a risk the picked
 * record itself cannot discriminate, since AbstractIndexer::fetchRecords()
 * already orders by tstamp DESC before applying the SQL LIMIT, so the
 * true most-recently-changed record is within the top-N-by-recency subset
 * regardless of which positive N was actually passed.
 *
 * withIndexingService()/withExcludeHiddenPages() are immutable on the real
 * indexer (each returns a new instance), so this decorator mirrors that:
 * it wraps the real with*() result in a NEW LimitCapturingIndexer sharing
 * the SAME LimitCapture instance, so a capture made after
 * AttributeOverviewModuleController's own
 * "$indexer->withIndexingService($indexingService)->findRecordUidsInScope(...)"
 * chain is still visible to the test.
 */
final readonly class LimitCapturingIndexer implements IndexerInterface
{
    public function __construct(
        private IndexerInterface $realIndexer,
        private LimitCapture $capture,
    ) {
    }

    #[Override]
    public function getTable(): string
    {
        return $this->realIndexer->getTable();
    }

    #[Override]
    public function findRecordUidsInScope(int $limit = 0): array
    {
        $recordUids = $this->realIndexer->findRecordUidsInScope($limit);

        $this->capture->capturedLimit          = $limit;
        $this->capture->capturedRecordUidCount = count($recordUids);

        return $recordUids;
    }

    #[Override]
    public function withIndexingService(IndexingService $indexingService): LimitCapturingIndexer
    {
        return new self(
            $this->realIndexer->withIndexingService($indexingService),
            $this->capture,
        );
    }

    #[Override]
    public function withExcludeHiddenPages(bool $excludeHiddenPages): LimitCapturingIndexer
    {
        return new self(
            $this->realIndexer->withExcludeHiddenPages($excludeHiddenPages),
            $this->capture,
        );
    }

    #[Override]
    public function indexRecord(IndexingService $indexingService, array $record): bool
    {
        return $this->realIndexer->indexRecord(
            $indexingService,
            $record,
        );
    }

    #[Override]
    public function dequeueOne(int $recordUid): IndexerInterface
    {
        return $this->realIndexer->dequeueOne($recordUid);
    }

    #[Override]
    public function dequeueMultiple(array $recordUids): IndexerInterface
    {
        return $this->realIndexer->dequeueMultiple($recordUids);
    }

    #[Override]
    public function dequeueAll(): IndexerInterface
    {
        return $this->realIndexer->dequeueAll();
    }

    #[Override]
    public function enqueueOne(int $recordUid): int
    {
        return $this->realIndexer->enqueueOne($recordUid);
    }

    #[Override]
    public function enqueueMultiple(array $recordUids): int
    {
        return $this->realIndexer->enqueueMultiple($recordUids);
    }

    #[Override]
    public function enqueueAll(): int
    {
        return $this->realIndexer->enqueueAll();
    }
}
