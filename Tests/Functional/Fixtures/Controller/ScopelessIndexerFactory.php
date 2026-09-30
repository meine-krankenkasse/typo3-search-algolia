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
 * ScopelessIndexer, which does not implement
 * InScopeRecordUidProviderInterface.
 *
 * Mirrors PhantomRecordUidIndexerFactory's established
 * delegate-to-a-real-instance, override-one-table pattern.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class ScopelessIndexerFactory extends IndexerFactory
{
    /**
     * @param IndexerFactory $realIndexerFactory The real factory every lookup is delegated to
     * @param string         $tableToWrap        The table whose indexer is wrapped in a ScopelessIndexer
     */
    public function __construct(
        private readonly IndexerFactory $realIndexerFactory,
        private readonly string $tableToWrap,
    ) {
    }

    #[Override]
    public function makeInstanceByType(string $type): ?IndexerInterface
    {
        $indexer = $this->realIndexerFactory->makeInstanceByType($type);

        if (
            !($indexer instanceof IndexerInterface)
            || ($type !== $this->tableToWrap)
        ) {
            return $indexer;
        }

        return new ScopelessIndexer($indexer);
    }
}
