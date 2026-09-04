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
 * Test double for IndexerInterface that delegates every call to a real,
 * container-resolved indexer instance, except that findRecordUidsInScope()
 * always returns a single UID that does not exist in the table, simulating
 * a custom IndexerInterface implementation (a documented public-API
 * extension point) returning a stale or otherwise non-existent record UID.
 *
 * Used to prove AttributeOverviewModuleController::buildTableAttributes()
 * falls back to STATUS_NO_RECORD_IN_SCOPE, rather than passing an empty
 * record array into DocumentBuilder::assemble(), when the record picked
 * from scope cannot actually be fetched.
 */
final readonly class PhantomRecordUidIndexer implements IndexerInterface
{
    public function __construct(
        private IndexerInterface $realIndexer,
        private int $phantomRecordUid,
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
        return [$this->phantomRecordUid];
    }

    #[Override]
    public function withIndexingService(IndexingService $indexingService): PhantomRecordUidIndexer
    {
        return new self(
            $this->realIndexer->withIndexingService($indexingService),
            $this->phantomRecordUid,
        );
    }

    #[Override]
    public function withExcludeHiddenPages(bool $excludeHiddenPages): PhantomRecordUidIndexer
    {
        return new self(
            $this->realIndexer->withExcludeHiddenPages($excludeHiddenPages),
            $this->phantomRecordUid,
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
