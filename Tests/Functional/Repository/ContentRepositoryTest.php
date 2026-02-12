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
     * Tests that findAllByPid() returns all content elements
     * on a page with the requested field selection.
     */
    #[Test]
    public function findAllByPidReturnsContentElements(): void
    {
        $elements = $this->subject->findAllByPid(2, ['uid', 'header', 'CType']);

        self::assertCount(2, $elements);
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

        self::assertCount(2, $elements);
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

        self::assertCount(1, $elements);
        self::assertSame('text', $elements[0]['CType']);
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
