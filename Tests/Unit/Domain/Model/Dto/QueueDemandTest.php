<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Domain\Model\Dto;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Dto\QueueDemand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QueueDemand.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(QueueDemand::class)]
class QueueDemandTest extends TestCase
{
    /**
     * Tests that a newly constructed QueueDemand has its indexing service
     * property initialized to zero. Verifies that getIndexingService()
     * returns 0 when no value has been explicitly set.
     */
    #[Test]
    public function indexingServiceDefaultsToZero(): void
    {
        $demand = new QueueDemand();

        self::assertSame(0, $demand->getIndexingService());
    }

    /**
     * Tests that setIndexingService() correctly stores the provided integer
     * value. Verifies that getIndexingService() returns the exact value
     * that was passed to the setter.
     */
    #[Test]
    public function setIndexingServiceStoresValue(): void
    {
        $demand = new QueueDemand();
        $demand->setIndexingService(5);

        self::assertSame(5, $demand->getIndexingService());
    }

    /**
     * Tests that setIndexingService() returns the QueueDemand instance itself
     * to support a fluent interface. Verifies that the return value is the
     * same object reference as the original demand.
     */
    #[Test]
    public function setIndexingServiceReturnsSelfForChaining(): void
    {
        $demand = new QueueDemand();
        $result = $demand->setIndexingService(1);

        self::assertSame($demand, $result);
    }

    /**
     * Tests that a newly constructed QueueDemand has its indexing services
     * collection initialized to an empty array. Verifies that
     * getIndexingServices() returns an empty array when no values have
     * been explicitly set.
     */
    #[Test]
    public function indexingServicesDefaultsToEmptyArray(): void
    {
        $demand = new QueueDemand();

        self::assertSame([], $demand->getIndexingServices());
    }

    /**
     * Tests that setIndexingServices() correctly stores the provided array
     * of string values. Verifies that getIndexingServices() returns the
     * exact same array that was passed to the setter.
     */
    #[Test]
    public function setIndexingServicesStoresValues(): void
    {
        $demand = new QueueDemand();
        $demand->setIndexingServices(['1', '2', '3']);

        self::assertSame(['1', '2', '3'], $demand->getIndexingServices());
    }

    /**
     * Tests that setIndexingServices() returns the QueueDemand instance itself
     * to support a fluent interface. Verifies that the return value is the
     * same object reference as the original demand.
     */
    #[Test]
    public function setIndexingServicesReturnsSelfForChaining(): void
    {
        $demand = new QueueDemand();
        $result = $demand->setIndexingServices([]);

        self::assertSame($demand, $result);
    }
}
