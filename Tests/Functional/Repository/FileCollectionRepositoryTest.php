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
     * QueryBuilder instance that holds that binding, otherwise the DBAL
     * driver raises an "unknown parameter" error at executeQuery() time.
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

        self::assertCount(2, $collections);
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

        self::assertCount(2, $collections);
    }
}
