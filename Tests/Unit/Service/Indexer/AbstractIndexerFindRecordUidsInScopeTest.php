<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\AbstractIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Fixtures\Service\Indexer\LegacyOverridePageIndexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Unit tests for AbstractIndexer::findRecordUidsInScope().
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(PageIndexer::class)]
#[CoversClass(AbstractIndexer::class)]
#[UsesClass(PageRepository::class)]
final class AbstractIndexerFindRecordUidsInScopeTest extends TestCase
{
    /**
     * Creates a PageIndexer mock with initQueueItemRecords() as the only
     * mocked method, standing in for a generic AbstractIndexer subclass
     * across all tests in this file, since findRecordUidsInScope() itself
     * is not overridden by any concrete indexer.
     */
    private function createIndexerMock(): MockObject&PageIndexer
    {
        return $this->getMockBuilder(PageIndexer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['initQueueItemRecords'])
            ->getMock();
    }

    /**
     * This is a thin-wrapper contract test: initQueueItemRecords() (the
     * delegate) is already covered by this repo's existing functional
     * indexer tests for the real SQL/scoping behavior. This test only
     * covers findRecordUidsInScope() itself (record_uid extraction + the
     * int cast), and does NOT independently prove real DB scoping. That
     * is additionally exercised end-to-end by AttributeOverviewModuleController's
     * own functional coverage of the real SCOPE_RECORD_LIMIT wiring.
     * Fixture values are deliberately strings, matching what
     * QueryBuilder::fetchAllAssociative() actually returns for an
     * unmapped/text-typed select column, so this exercises the (int) cast
     * for real, a literal-int fixture would pass even if the cast were
     * accidentally dropped.
     *
     * The limit reaches initQueueItemRecords() through the
     * queueItemRecordLimit property, not an argument. The real indexers'
     * limit tests (FileIndexerTest and the functional
     * AbstractIndexerFindRecordUidsInScope tests) cover that it arrives.
     */
    #[Test]
    public function findRecordUidsInScopeReturnsOnlyTheRecordUidColumnAsIntegers(): void
    {
        $indexer = $this->createIndexerMock();

        $indexer
            ->expects(self::once())
            ->method('initQueueItemRecords')
            ->with([])
            ->willReturn([
                ['record_uid' => '1', 'table_name' => 'pages', 'service_uid' => 1, 'changed' => 0, 'priority' => 0],
                ['record_uid' => '8', 'table_name' => 'pages', 'service_uid' => 1, 'changed' => 0, 'priority' => 0],
            ]);

        $indexer = $indexer->withIndexingService(
            self::createStub(IndexingService::class),
        );

        self::assertSame([1, 8], $indexer->findRecordUidsInScope());
    }

    /**
     * Verifies a subclass written against 3.0.0, overriding
     * initQueueItemRecords() with the 3.0.0 signature, still loads, and that
     * findRecordUidsInScope() caps its result to a positive limit even when
     * that override ignores the limit and returns more records.
     */
    #[Test]
    public function findRecordUidsInScopeCapsTheResultOfALegacyInitQueueItemRecordsOverride(): void
    {
        $connectionPool = self::createStub(ConnectionPool::class);

        $indexer = new LegacyOverridePageIndexer(
            $connectionPool,
            self::createStub(SiteFinder::class),
            new PageRepository($connectionPool),
            self::createStub(SearchEngineFactory::class),
            self::createStub(QueueItemRepository::class),
            self::createStub(DocumentBuilder::class),
        );

        $indexer = $indexer->withIndexingService(
            self::createStub(IndexingService::class),
        );

        self::assertSame([5, 4], $indexer->findRecordUidsInScope(2));
        self::assertSame([5, 4, 3], $indexer->findRecordUidsInScope());
    }

    /**
     * Verifies findRecordUidsInScope() throws when no indexing service is
     * set, matching the throws contract documented on InScopeRecordUidProviderInterface and
     * the same guard every sibling method (enqueueOne(), dequeueOne(),
     * etc.) uses.
     */
    #[Test]
    public function findRecordUidsInScopeThrowsWithoutAnIndexingService(): void
    {
        $indexer = $this->createIndexerMock();

        $indexer->expects(self::never())->method('initQueueItemRecords');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing indexing service instance.');

        $indexer->findRecordUidsInScope();
    }
}
