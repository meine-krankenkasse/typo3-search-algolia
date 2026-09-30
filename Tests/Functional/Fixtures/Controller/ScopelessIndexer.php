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
 * failing, so adding the scope lookup stayed a non-breaking change for
 * existing IndexerInterface implementations.
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

    #[Override]
    public function getTable(): string
    {
        return $this->realIndexer->getTable();
    }

    #[Override]
    public function withIndexingService(IndexingService $indexingService): ScopelessIndexer
    {
        return new self($this->realIndexer->withIndexingService($indexingService));
    }

    #[Override]
    public function withExcludeHiddenPages(bool $excludeHiddenPages): ScopelessIndexer
    {
        return new self($this->realIndexer->withExcludeHiddenPages($excludeHiddenPages));
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
