<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_column;

/**
 * Functional tests for ContentRepository.
 *
 * Tests content element queries: findAllByPid, findInfo.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(ContentRepository::class)]
final class ContentRepositoryTest extends AbstractFunctionalTestCase
{
    private ContentRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');

        $this->subject = new ContentRepository($this->getConnectionPool());
    }

    /**
     * Asserts that the given result rows carry exactly the expected uid values,
     * ignoring order (the underlying query has no ORDER BY).
     *
     * @param int[]                            $expectedUids The uid values every returned row is expected to carry
     * @param array<int, array<string, mixed>> $elements     The rows returned by findAllByPid() to check
     */
    private function assertUids(array $expectedUids, array $elements): void
    {
        self::assertEqualsCanonicalizing($expectedUids, array_column($elements, 'uid'));
    }

    /**
     * Tests that findAllByPid() returns all content elements
     * on a page with the requested field selection.
     */
    #[Test]
    public function findAllByPidReturnsContentElements(): void
    {
        $elements = $this->subject->findAllByPid(2, ['uid', 'header', 'CType']);

        $this->assertUids([1, 2, 4, 5], $elements);
    }

    /**
     * Tests that findAllByPid() returns an empty array when the
     * specified page contains no content elements.
     */
    #[Test]
    public function findAllByPidReturnsEmptyForPageWithoutContent(): void
    {
        $elements = $this->subject->findAllByPid(1, ['uid']);

        self::assertSame([], $elements);
    }

    /**
     * Tests that findAllByPid() only returns the fields specified
     * in the selection parameter, excluding all others.
     */
    #[Test]
    public function findAllByPidFiltersSelectedFields(): void
    {
        $elements = $this->subject->findAllByPid(2, ['uid']);

        $this->assertUids([1, 2, 4, 5], $elements);
        self::assertArrayHasKey('uid', $elements[0]);
        self::assertArrayNotHasKey('header', $elements[0]);
    }

    /**
     * Tests that findAllByPid() filters content elements by the
     * specified CType values, returning only matching elements.
     */
    #[Test]
    public function findAllByPidFiltersByContentElementType(): void
    {
        $elements = $this->subject->findAllByPid(2, ['uid', 'CType'], ['text']);

        $this->assertUids([1, 4, 5], $elements);
    }

    /**
     * Tests that findAllByPid() leaves out content elements on the excluded
     * colPos values and keeps all others.
     */
    #[Test]
    public function findAllByPidExcludesColPos(): void
    {
        $elements = $this->subject->findAllByPid(2, ['uid'], excludeColPos: [9999]);

        $this->assertUids([1, 2, 5], $elements);
    }

    /**
     * Tests that findAllByPid() correctly combines the content element type
     * filter and the colPos exclusion, returning only elements matching both.
     */
    #[Test]
    public function findAllByPidCombinesContentElementTypeAndExcludedColPos(): void
    {
        $elements = $this->subject->findAllByPid(
            2,
            ['uid', 'CType'],
            ['text'],
            [9999],
        );

        $this->assertUids([1, 5], $elements);
    }

    /**
     * Tests that findAllByPid() excludes several colPos values at once, not
     * just a single one.
     */
    #[Test]
    public function findAllByPidExcludesMultipleColPosValues(): void
    {
        $elements = $this->subject->findAllByPid(2, ['uid'], excludeColPos: [9999, 2]);

        $this->assertUids([1, 2], $elements);
    }

    /**
     * Tests that findAllByPid() excludes elements on colPos 0, which must not
     * be dropped as if no colPos had been passed.
     */
    #[Test]
    public function findAllByPidExcludesColPosZero(): void
    {
        // The shared fixture has no colPos 0 row, so this test adds its own.
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert('tt_content', [
                'uid'     => 6,
                'pid'     => 2,
                'header'  => 'Main Column Content',
                'CType'   => 'text',
                'deleted' => 0,
                'hidden'  => 0,
                'colPos'  => 0,
            ]);

        $elements = $this->subject->findAllByPid(2, ['uid'], excludeColPos: [0]);

        $this->assertUids([1, 2, 4, 5], $elements);
    }

    /**
     * Tests that findInfo() returns the header text and parent page UID
     * for an existing content element.
     */
    #[Test]
    public function findInfoReturnsHeaderAndPageUid(): void
    {
        $info = $this->subject->findInfo(1);

        self::assertSame('Text Content', $info['header']);
        self::assertSame(2, $info['page_uid']);
    }

    /**
     * Tests that findInfo() returns an empty array when the content
     * element does not exist in the database.
     */
    #[Test]
    public function findInfoReturnsEmptyForNonExistent(): void
    {
        $info = $this->subject->findInfo(999);

        self::assertSame([], $info);
    }
}
