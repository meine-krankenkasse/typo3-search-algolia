<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Result;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

/**
 * Unit tests for RecordRepository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordRepository::class)]
final class RecordRepositoryTest extends TestCase
{
    /**
     * Tests that findUidsWhere() hands the values over as strings. The field can be a string
     * column of an inline relation, and a comparison of such a column with integers cannot use
     * a key on it and reads a value that only begins with a number as that number. The test
     * database is not able to show the difference, so the binding itself is pinned.
     */
    #[Test]
    public function findUidsWhereBindsTheValuesAsStrings(): void
    {
        $restrictions = self::createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeByType')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();

        $result = self::createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn(self::createStub(ExpressionBuilder::class));
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder
            ->expects(self::once())
            ->method('createNamedParameter')
            ->with(['7', '12'], ArrayParameterType::STRING)
            ->willReturn(':dcValue1');

        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $subject = new RecordRepository($connectionPool);

        self::assertSame([], $subject->findUidsWhere('tt_content', 'tx_test_parent', [7, 12]));
    }
}
