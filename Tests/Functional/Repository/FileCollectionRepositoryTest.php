<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Collection\AbstractFileCollection;
use TYPO3\CMS\Core\Resource\Collection\FileCollectionRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_map;

/**
 * Functional tests for FileCollectionRepository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(FileCollectionRepository::class)]
final class FileCollectionRepositoryTest extends AbstractFunctionalTestCase
{
    private FileCollectionRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_file_collection.csv');

        $this->subject = new FileCollectionRepository(
            $this->getConnectionPool(),
            GeneralUtility::makeInstance(FileCollectionRegistry::class),
        );
    }

    /**
     * Tests that findAllByCollectionUids() with a non-empty UID filter
     * actually executes and returns only the matching collections. This is
     * a regression test for the SQL parameterization fix: the constrained
     * IN(...) clause is built with a named parameter bound via
     * createNamedParameter(), and the query must run against the same
     * QueryBuilder instance that holds that binding. Otherwise the bound
     * value never reaches the executing QueryBuilder, and the query
     * silently matches zero rows instead of throwing.
     */
    #[Test]
    public function findAllByCollectionUidsReturnsOnlyMatchingCollections(): void
    {
        $collections = $this->subject->findAllByCollectionUids([1]);

        self::assertCount(1, $collections);
        self::assertInstanceOf(AbstractFileCollection::class, $collections[0]);
        self::assertSame(1, $collections[0]->getUid());
    }

    /**
     * Tests that findAllByCollectionUids() with multiple UIDs returns all
     * of them, confirming the ArrayParameterType::INTEGER binding correctly
     * expands to multiple values in the IN(...) clause rather than being
     * treated as a single scalar parameter.
     */
    #[Test]
    public function findAllByCollectionUidsReturnsAllMatchingCollectionsForMultipleUids(): void
    {
        $collections = $this->subject->findAllByCollectionUids([1, 2]);

        self::assertSame(
            [1, 2],
            array_map(
                static fn (AbstractFileCollection $collection): int => $collection->getUid(),
                $collections,
            ),
        );
    }

    /**
     * Tests that findAllByCollectionUids() excludes soft-deleted collections
     * from the result, confirming the deleted restriction is still applied
     * on the query that actually executes.
     */
    #[Test]
    public function findAllByCollectionUidsExcludesDeletedCollections(): void
    {
        $collections = $this->subject->findAllByCollectionUids([3]);

        self::assertSame([], $collections);
    }

    /**
     * Tests that findAllByCollectionUids() without any UID filter returns
     * all non-deleted collections.
     */
    #[Test]
    public function findAllByCollectionUidsReturnsAllCollectionsWhenNoFilterGiven(): void
    {
        $collections = $this->subject->findAllByCollectionUids();

        self::assertSame(
            [1, 2],
            array_map(
                static fn (AbstractFileCollection $collection): int => $collection->getUid(),
                $collections,
            ),
        );
    }

    /**
     * Tests that a malicious array element does not alter the query
     * structure. Before the SQL parameterization fix, findAllByCollectionUids()
     * built the IN(...) clause by interpolating $collectionIds directly into
     * the SQL string via QueryBuilder::expr()->in(), with no escaping. A
     * payload like '999) OR 1=1 OR (1' would have widened the WHERE clause
     * into an always-true condition, returning every non-deleted collection
     * regardless of uid. With the fix, the array is bound as a single
     * ArrayParameterType::INTEGER parameter, so the payload is cast to its
     * leading integer (999, which matches no fixture row) rather than being
     * interpreted as SQL.
     */
    #[Test]
    public function findAllByCollectionUidsKeepsQueryParameterizedForMaliciousInput(): void
    {
        // Deliberately violates the declared int[] parameter type: PHP does
        // not enforce array element types at runtime, so a caller (or, in
        // the pre-fix code, an attacker) can supply exactly this kind of
        // value. The resulting PHPStan finding is baselined, not suppressed
        // inline, so it stays visible and auditable in Build/phpstan-baseline.neon.
        $collections = $this->subject->findAllByCollectionUids(['999) OR 1=1 OR (1']);

        self::assertSame([], $collections);
    }
}
