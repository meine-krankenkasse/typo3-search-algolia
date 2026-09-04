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
 * container-resolved IndexerFactory instance, except that it returns NULL
 * for exactly one configured table, regardless of what the real factory
 * would have resolved.
 *
 * Used to reach AttributeOverviewModuleController::buildTableAttributes()'s
 * "!($indexer instanceof IndexerInterface)" branch through the real public
 * flow (indexAction()): that branch is unreachable through real production
 * wiring, because getRecordTypes() only ever iterates
 * IndexerRegistry::getRegisteredIndexers() and makeInstanceByType() only
 * returns NULL for a type absent from that same registry, but the branch is
 * still real defensive code guarding a documented public-API return type
 * (?IndexerInterface), not dead code, so it needs a real test double that
 * forces the documented-but-otherwise-unreachable NULL outcome for one
 * table that DOES have an indexing service configured (unlike the sibling
 * "no indexing service configured at all" scenario, which never reaches
 * this branch).
 *
 * Mirrors LimitCapturingIndexerFactory's established
 * delegate-to-a-real-instance, override-one-table pattern.
 */
final class NullReturningIndexerFactory extends IndexerFactory
{
    public function __construct(
        private readonly IndexerFactory $realIndexerFactory,
        private readonly string $tableToNullOut,
    ) {
    }

    #[Override]
    public function makeInstanceByType(string $type): ?IndexerInterface
    {
        if ($type === $this->tableToNullOut) {
            return null;
        }

        return $this->realIndexerFactory->makeInstanceByType($type);
    }
}
