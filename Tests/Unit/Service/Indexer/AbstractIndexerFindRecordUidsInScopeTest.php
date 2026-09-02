<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\AbstractIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for AbstractIndexer::findRecordUidsInScope().
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(PageIndexer::class)]
#[CoversClass(AbstractIndexer::class)]
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
     * Also asserts, via a `with(...)` constraint rather than a bare
     * `expects(self::once())`, that the $limit argument is genuinely
     * forwarded to initQueueItemRecords() unchanged: without this
     * constraint, a regression that silently drops the limit parameter
     * (making the cap a no-op) would not be caught, since the mock would
     * still match and return the same fixture regardless of what was
     * actually passed.
     */
    #[Test]
    public function findRecordUidsInScopeReturnsOnlyTheRecordUidColumnAsIntegers(): void
    {
        $indexer = $this->createIndexerMock();

        $indexer
            ->expects(self::once())
            ->method('initQueueItemRecords')
            ->with([], 0)
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
     * Verifies the $limit argument reaches initQueueItemRecords() as given,
     * for a positive limit specifically (the zero/default case is already
     * covered above), the case that actually matters for
     * AttributeOverviewModuleController's SCOPE_RECORD_LIMIT cap.
     */
    #[Test]
    public function findRecordUidsInScopeForwardsAPositiveLimitToInitQueueItemRecords(): void
    {
        $indexer = $this->createIndexerMock();

        $indexer
            ->expects(self::once())
            ->method('initQueueItemRecords')
            ->with([], 2)
            ->willReturn([
                ['record_uid' => '3', 'table_name' => 'pages', 'service_uid' => 1, 'changed' => 0, 'priority' => 0],
                ['record_uid' => '2', 'table_name' => 'pages', 'service_uid' => 1, 'changed' => 0, 'priority' => 0],
            ]);

        $indexer = $indexer->withIndexingService(
            self::createStub(IndexingService::class),
        );

        self::assertSame([3, 2], $indexer->findRecordUidsInScope(2));
    }

    /**
     * Verifies findRecordUidsInScope() throws when no indexing service is
     * set, matching the throws contract documented on IndexerInterface and
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
