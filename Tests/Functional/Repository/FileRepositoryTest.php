<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for FileRepository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(FileRepository::class)]
final class FileRepositoryTest extends AbstractFunctionalTestCase
{
    private FileRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_file_metadata.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_file_reference.csv');

        $this->subject = new FileRepository($this->getConnectionPool());
    }

    #[Test]
    public function getFileUidByMetadataUidReturnsFileUid(): void
    {
        $fileUid = $this->subject->getFileUidByMetadataUid(10);

        self::assertSame(1, $fileUid);
    }

    #[Test]
    public function getFileUidByMetadataUidReturnsFalseForNonExistent(): void
    {
        $fileUid = $this->subject->getFileUidByMetadataUid(99999);

        self::assertFalse($fileUid);
    }

    #[Test]
    public function findInfoReturnsFileInformation(): void
    {
        $info = $this->subject->findInfo(10);

        self::assertSame('test.pdf', $info['name']);
        self::assertSame('application/pdf', $info['type']);
    }

    #[Test]
    public function findInfoReturnsEmptyArrayForNonExistent(): void
    {
        $info = $this->subject->findInfo(99999);

        self::assertSame([], $info);
    }

    #[Test]
    public function findUsagesReturnsContentElementUids(): void
    {
        $usages = $this->subject->findUsages(10);

        self::assertCount(2, $usages);

        $uids = array_column($usages, 'uid');
        self::assertContains(1, $uids);
        self::assertContains(2, $uids);
    }

    #[Test]
    public function findUsagesExcludesDeletedReferences(): void
    {
        // File 2 (metadata uid=11) has only a deleted reference (uid=3)
        $usages = $this->subject->findUsages(11);

        self::assertSame([], $usages);
    }

    #[Test]
    public function hasFileReferenceReturnsTrueWhenReferenceExists(): void
    {
        $result = $this->subject->hasFileReference(1, 'tt_content', [1, 2]);

        self::assertTrue($result);
    }

    #[Test]
    public function hasFileReferenceReturnsFalseForEmptyForeignUids(): void
    {
        $result = $this->subject->hasFileReference(1, 'tt_content', []);

        self::assertFalse($result);
    }

    #[Test]
    public function hasFileReferenceReturnsFalseWhenNoReferenceExists(): void
    {
        $result = $this->subject->hasFileReference(99, 'tt_content', [1, 2]);

        self::assertFalse($result);
    }
}
