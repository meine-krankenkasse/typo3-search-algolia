<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use Override;

/**
 * Test double for IndexerFactory that delegates every lookup to a real,
 * container-resolved IndexerFactory instance, except that the indexer
 * resolved for exactly one configured table is wrapped in a
 * PhantomRecordUidIndexer, so its findRecordUidsInScope() always returns
 * a non-existent record UID for that table.
 *
 * IndexerFactory has no dedicated interface (like DocumentBuilder, see
 * ThrowingForTableDocumentBuilder), so this mirrors the same established
 * pattern as LimitCapturingIndexerFactory: extend the concrete class and
 * delegate to a real, wrapped instance rather than reimplementing its
 * lookup logic.
 */
final class PhantomRecordUidIndexerFactory extends IndexerFactory
{
    public function __construct(
        private readonly IndexerFactory $realIndexerFactory,
        private readonly string $tableToSpyOn,
        private readonly int $phantomRecordUid,
    ) {
    }

    #[Override]
    public function makeInstanceByType(string $type): ?IndexerInterface
    {
        $indexer = $this->realIndexerFactory->makeInstanceByType($type);

        if (!($indexer instanceof IndexerInterface) || ($type !== $this->tableToSpyOn)) {
            return $indexer;
        }

        return new PhantomRecordUidIndexer(
            $indexer,
            $this->phantomRecordUid,
        );
    }
}
