<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Registry;

/**
 * Unit tests for QueueStatusService.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(QueueStatusService::class)]
class QueueStatusServiceTest extends TestCase
{
    /**
     * Tests that setLastExecutionTime() stores the provided timestamp in the
     * TYPO3 registry under the extension's namespace with the key 'last-exec-time'.
     * Verifies the registry set() method is called exactly once with the correct arguments.
     */
    #[Test]
    public function setLastExecutionTimeStoresTimestampInRegistry(): void
    {
        $registryMock = $this->createMock(Registry::class);
        $registryMock
            ->expects(self::once())
            ->method('set')
            ->with(
                Constants::EXTENSION_NAME,
                'last-exec-time',
                1700000000
            );

        $service = new QueueStatusService($registryMock);
        $service->setLastExecutionTime(1700000000);
    }

    /**
     * Tests that getLastExecutionTime() retrieves and returns the timestamp
     * previously stored in the TYPO3 registry under the extension's namespace
     * with the key 'last-exec-time'.
     */
    #[Test]
    public function getLastExecutionTimeReturnsStoredTimestamp(): void
    {
        $registryMock = $this->createMock(Registry::class);
        $registryMock
            ->method('get')
            ->with(Constants::EXTENSION_NAME, 'last-exec-time')
            ->willReturn(1700000000);

        $service = new QueueStatusService($registryMock);

        self::assertSame(1700000000, $service->getLastExecutionTime());
    }

    /**
     * Tests that getLastExecutionTime() returns zero when the registry contains
     * no stored timestamp (returns null), providing a safe default value.
     */
    #[Test]
    public function getLastExecutionTimeReturnsZeroWhenNoTimestampStored(): void
    {
        $registryMock = $this->createMock(Registry::class);
        $registryMock
            ->method('get')
            ->with(Constants::EXTENSION_NAME, 'last-exec-time')
            ->willReturn(null);

        $service = new QueueStatusService($registryMock);

        self::assertSame(0, $service->getLastExecutionTime());
    }
}
