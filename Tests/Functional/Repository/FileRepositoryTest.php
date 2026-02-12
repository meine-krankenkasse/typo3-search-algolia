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

    /**
     * Tests that getFileUidByMetadataUid() returns the corresponding
     * file UID for a given metadata record UID.
     */
    #[Test]
    public function getFileUidByMetadataUidReturnsFileUid(): void
    {
        $fileUid = $this->subject->getFileUidByMetadataUid(10);

        self::assertSame(1, $fileUid);
    }

    /**
     * Tests that getFileUidByMetadataUid() returns false when no
     * metadata record exists with the given UID.
     */
    #[Test]
    public function getFileUidByMetadataUidReturnsFalseForNonExistent(): void
    {
        $fileUid = $this->subject->getFileUidByMetadataUid(99999);

        self::assertFalse($fileUid);
    }

    /**
     * Tests that findInfo() returns the file name and MIME type
     * for an existing file metadata record.
     */
    #[Test]
    public function findInfoReturnsFileInformation(): void
    {
        $info = $this->subject->findInfo(10);

        self::assertSame('test.pdf', $info['name']);
        self::assertSame('application/pdf', $info['type']);
    }

    /**
     * Tests that findInfo() returns an empty array when the file
     * metadata record does not exist in the database.
     */
    #[Test]
    public function findInfoReturnsEmptyArrayForNonExistent(): void
    {
        $info = $this->subject->findInfo(99999);

        self::assertSame([], $info);
    }

    /**
     * Tests that findUsages() returns the content element UIDs that
     * reference the given file through sys_file_reference.
     */
    #[Test]
    public function findUsagesReturnsContentElementUids(): void
    {
        $usages = $this->subject->findUsages(10);

        self::assertCount(2, $usages);

        $uids = array_column($usages, 'uid');
        self::assertContains(1, $uids);
        self::assertContains(2, $uids);
    }

    /**
     * Tests that findUsages() excludes deleted file references
     * and returns an empty array when all references are deleted.
     */
    #[Test]
    public function findUsagesExcludesDeletedReferences(): void
    {
        // File 2 (metadata uid=11) has only a deleted reference (uid=3)
        $usages = $this->subject->findUsages(11);

        self::assertSame([], $usages);
    }

    /**
     * Tests that hasFileReference() returns true when an active file
     * reference exists for the given file UID, table and foreign UIDs.
     */
    #[Test]
    public function hasFileReferenceReturnsTrueWhenReferenceExists(): void
    {
        $result = $this->subject->hasFileReference(1, 'tt_content', [1, 2]);

        self::assertTrue($result);
    }

    /**
     * Tests that hasFileReference() returns false when an empty
     * array of foreign UIDs is provided.
     */
    #[Test]
    public function hasFileReferenceReturnsFalseForEmptyForeignUids(): void
    {
        $result = $this->subject->hasFileReference(1, 'tt_content', []);

        self::assertFalse($result);
    }

    /**
     * Tests that hasFileReference() returns false when no file reference
     * exists for the given file UID and foreign record combination.
     */
    #[Test]
    public function hasFileReferenceReturnsFalseWhenNoReferenceExists(): void
    {
        $result = $this->subject->hasFileReference(99, 'tt_content', [1, 2]);

        self::assertFalse($result);
    }
}
