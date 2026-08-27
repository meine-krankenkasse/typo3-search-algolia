<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Override;
use PDO;
use Pdo\Sqlite;
use ReflectionProperty;
use TYPO3\CMS\Core\Database\Driver\DriverConnection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Abstract base class for functional tests in the typo3-search-algolia extension.
 *
 * Provides shared setup, helper methods, and fixture loading for all
 * functional test cases. Uses SQLite as database backend via the
 * TYPO3 testing framework.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    /**
     * Core extensions required for functional tests.
     *
     * @var array<non-empty-string>
     */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    /**
     * Test extensions to load.
     *
     * @var array<non-empty-string>
     */
    protected array $testExtensionsToLoad = [
        'meine-krankenkasse/typo3-search-algolia',
    ];

    /**
     * Registers custom SQLite functions that are available in MySQL but not in SQLite.
     *
     * This is necessary because the production code uses MySQL-specific functions
     * like GREATEST() which are not natively available in SQLite. The connection
     * pool only exposes the Doctrine DBAL wrapper, so reflection is used to reach
     * the underlying driver connection and its native PDO handle.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $connection       = $this->getConnectionPool()->getConnectionByName('Default');
        $reflProperty     = new ReflectionProperty(DoctrineConnection::class, '_conn');
        $driverConnection = $reflProperty->getValue($connection);

        if ($driverConnection instanceof DriverConnection) {
            $pdo = $driverConnection->getNativeConnection();

            if ($pdo instanceof Sqlite) {
                // PDO::sqliteCreateFunction() is deprecated since PHP 8.5.0 in favour
                // of the driver-specific Pdo\Sqlite::createFunction() (available since
                // PHP 8.4.0, when the PDO driver-specific subclasses were introduced).
                $pdo->createFunction(
                    'GREATEST',
                    max(...),
                    -1,
                );
            } elseif (
                ($pdo instanceof PDO)
                && ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite')
            ) {
                $pdo->sqliteCreateFunction(
                    'GREATEST',
                    max(...),
                    -1,
                );
            }
        }
    }

    /**
     * Returns a query builder for the given table with all default restrictions removed.
     *
     * @param string $tableName
     *
     * @return QueryBuilder
     */
    protected function getQueryBuilderWithoutRestrictions(string $tableName): QueryBuilder
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($tableName);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    /**
     * Fetches a single row from the database for the given table and field value,
     * ignoring all default restrictions.
     *
     * @param string                $tableName
     * @param string                $fieldName
     * @param string|int|float|bool $fieldValue
     *
     * @return array<string, scalar|null>|false
     */
    protected function fetchFirstRowByFieldValue(
        string $tableName,
        string $fieldName,
        string|int|float|bool $fieldValue,
    ): array|false {
        $queryBuilder = $this->getQueryBuilderWithoutRestrictions($tableName);

        return $queryBuilder
            ->select('*')
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq(
                    $fieldName,
                    $queryBuilder->createNamedParameter($fieldValue)
                )
            )
            ->executeQuery()
            ->fetchAssociative();
    }
}
