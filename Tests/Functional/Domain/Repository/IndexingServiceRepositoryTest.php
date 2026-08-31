<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Domain\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

/**
 * Functional tests for IndexingServiceRepository.
 *
 * Tests the row-ordering contract of findAllByTableName(), which the
 * AttributeOverviewModuleController relies on for its "keep the first
 * configured indexing service" tie-break.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(IndexingServiceRepository::class)]
final class IndexingServiceRepositoryTest extends AbstractFunctionalTestCase
{
    private IndexingServiceRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = $this->get(IndexingServiceRepository::class);
    }

    /**
     * Proves findAllByTableName() explicitly configures a uid-ascending
     * ordering criterion on the query it builds, not merely that the
     * returned rows happen to come back in that order.
     *
     * A plain "assert the returned rows are uid-ascending" check would NOT
     * be discriminating here: the table's 'uid' column is declared as
     * "int(11) unsigned NOT NULL auto_increment, PRIMARY KEY (uid)"
     * (ext_tables.sql), which on the SQLite backend used by these
     * functional tests (Build/FunctionalTests.xml) becomes the rowid
     * alias, so SQLite always returns a plain, unindexed table scan in
     * ascending uid order, independent of the physical row insertion
     * sequence and independent of whether an ORDER BY clause is present at
     * all. Spot-checked directly: importing the fixture rows in
     * descending-uid file order and running a raw, un-ordered
     * "SELECT uid FROM ... WHERE type = 'pages'" against the functional
     * test database still returned them uid-ascending. So a test that only
     * inspects the final row order would pass whether or not
     * setOrderings() exists in findAllByTableName(), and would not catch
     * its removal.
     *
     * Instead, this asserts on QueryInterface::getOrderings() of the
     * actual Query object the repository method built (obtained via the
     * public QueryResultInterface::getQuery() API), which directly
     * reflects whether setOrderings() was called, independent of any
     * incidental storage-engine scan order. Removing setOrderings() from
     * findAllByTableName() makes getOrderings() return an empty array,
     * which fails this assertion.
     */
    #[Test]
    public function findAllByTableNameConfiguresUidAscendingOrdering(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/attribute_overview_indexing_services_overlap.csv');

        $queryResult = $this->subject->findAllByTableName('pages');

        self::assertSame(
            [
                'uid' => QueryInterface::ORDER_ASCENDING,
            ],
            $queryResult->getQuery()->getOrderings(),
            'findAllByTableName() must configure an explicit uid-ascending ordering criterion so callers '
            . 'can rely on a deterministic, pinned row order instead of incidental database/execution-plan order.',
        );
    }
}
