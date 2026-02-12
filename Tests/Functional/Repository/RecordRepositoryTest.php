<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for RecordRepository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordRepository::class)]
final class RecordRepositoryTest extends AbstractFunctionalTestCase
{
    private RecordRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');

        $this->subject = new RecordRepository($this->getConnectionPool());
    }

    #[Test]
    public function findPidReturnsPidForExistingPage(): void
    {
        $pid = $this->subject->findPid('pages', 2);

        self::assertSame(1, $pid);
    }

    #[Test]
    public function findPidReturnsPidForContentElement(): void
    {
        $pid = $this->subject->findPid('tt_content', 1);

        self::assertSame(2, $pid);
    }

    #[Test]
    public function findPidReturnsFalseForNonExistentRecord(): void
    {
        $pid = $this->subject->findPid('pages', 99999);

        self::assertFalse($pid);
    }

    #[Test]
    public function findPidReturnsZeroPidForRootPage(): void
    {
        $pid = $this->subject->findPid('pages', 1);

        self::assertSame(0, $pid);
    }
}
