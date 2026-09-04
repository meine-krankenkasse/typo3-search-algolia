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
 * LimitCapturingIndexer, so the test can observe the $limit argument
 * AttributeOverviewModuleController::buildTableAttributes() actually passes
 * to IndexerInterface::findRecordUidsInScope() for that table.
 *
 * IndexerFactory has no dedicated interface (like DocumentBuilder, see
 * ThrowingForTableDocumentBuilder), so this mirrors that same established
 * pattern: extend the concrete class and delegate to a real, wrapped
 * instance rather than reimplementing its lookup logic.
 */
final class LimitCapturingIndexerFactory extends IndexerFactory
{
    public function __construct(
        private readonly IndexerFactory $realIndexerFactory,
        private readonly string $tableToSpyOn,
        private readonly LimitCapture $capture,
    ) {
    }

    #[Override]
    public function makeInstanceByType(string $type): ?IndexerInterface
    {
        $indexer = $this->realIndexerFactory->makeInstanceByType($type);

        if (!($indexer instanceof IndexerInterface) || ($type !== $this->tableToSpyOn)) {
            return $indexer;
        }

        return new LimitCapturingIndexer(
            $indexer,
            $this->capture,
        );
    }
}
