<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use Closure;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use Override;

/**
 * Test double for IndexerFactory that delegates every lookup to a real,
 * container-resolved IndexerFactory instance, except that the indexer
 * resolved for exactly one table is replaced by whatever $override returns
 * for it: a wrapping test double, or NULL.
 *
 * IndexerFactory has no dedicated interface (like DocumentBuilder, see
 * ThrowingForTableDocumentBuilder), so this extends the concrete class and
 * delegates to a real, wrapped instance rather than reimplementing its
 * lookup logic.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class TableOverridingIndexerFactory extends IndexerFactory
{
    /**
     * @param IndexerFactory                               $realIndexerFactory The real factory every
     *                                                                         lookup is delegated to
     * @param string                                       $table              The table whose indexer
     *                                                                         is overridden
     * @param Closure(IndexerInterface): ?IndexerInterface $override           Maps the real indexer of
     *                                                                         $table to its replacement
     */
    public function __construct(
        private readonly IndexerFactory $realIndexerFactory,
        private readonly string $table,
        private readonly Closure $override,
    ) {
    }

    /**
     * Returns the real indexer for every table except $table, whose real
     * indexer is passed through $override.
     *
     * @param string $type The table name to resolve the indexer for
     *
     * @return IndexerInterface|null The real or the overridden indexer
     */
    #[Override]
    public function makeInstanceByType(string $type): ?IndexerInterface
    {
        $indexer = $this->realIndexerFactory->makeInstanceByType($type);

        if (
            !($indexer instanceof IndexerInterface)
            || ($type !== $this->table)
        ) {
            return $indexer;
        }

        return ($this->override)($indexer);
    }
}
