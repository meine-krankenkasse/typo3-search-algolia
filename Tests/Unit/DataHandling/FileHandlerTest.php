<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\DataHandling;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\FileHandler;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\MetaDataAspect;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * Unit tests for FileHandler.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(FileHandler::class)]
class FileHandlerTest extends TestCase
{
    private FileHandler $fileHandler;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fileHandler = new FileHandler();
    }

    /**
     * Tests that getMetadataUid() returns the numeric UID from the metadata
     * when the File object has valid metadata containing a non-zero 'uid' key.
     */
    #[Test]
    public function getMetadataUidReturnsUidForFileWithValidMetadata(): void
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn(['uid' => 123]);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        self::assertSame(123, $this->fileHandler->getMetadataUid($fileMock));
    }

    /**
     * Tests that getMetadataUid() returns false when the metadata array contains
     * a 'uid' key with a value of zero, indicating no valid metadata record exists.
     */
    #[Test]
    public function getMetadataUidReturnsFalseForZeroUid(): void
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn(['uid' => 0]);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        self::assertFalse($this->fileHandler->getMetadataUid($fileMock));
    }

    /**
     * Tests that getMetadataUid() returns false when the metadata array is empty,
     * meaning no metadata properties are available at all.
     */
    #[Test]
    public function getMetadataUidReturnsFalseForEmptyMetadata(): void
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn([]);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        self::assertFalse($this->fileHandler->getMetadataUid($fileMock));
    }

    /**
     * Tests that getMetadataUid() returns false when the metadata array contains
     * properties but the 'uid' key is missing entirely, such as when only a 'title'
     * key is present.
     */
    #[Test]
    public function getMetadataUidReturnsFalseForMissingUidKey(): void
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn(['title' => 'Test']);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        self::assertFalse($this->fileHandler->getMetadataUid($fileMock));
    }

    /**
     * Tests that getMetadataFromFile() returns the full metadata array when given
     * a File instance directly, retrieving it through the file's MetaDataAspect.
     */
    #[Test]
    public function getMetadataFromFileReturnsMetadataForFileInstance(): void
    {
        $metadata = ['uid' => 1, 'title' => 'Test File'];

        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn($metadata);

        $fileMock = $this->createMock(File::class);
        $fileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        self::assertSame($metadata, $this->fileHandler->getMetadataFromFile($fileMock));
    }

    /**
     * Tests that getMetadataFromFile() resolves a FileReference to its original File
     * and returns the metadata from that underlying file object.
     */
    #[Test]
    public function getMetadataFromFileReturnsMetadataForFileReference(): void
    {
        $metadata = ['uid' => 2, 'title' => 'Referenced File'];

        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn($metadata);

        $originalFileMock = $this->createMock(File::class);
        $originalFileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        $fileReferenceMock = $this->createMock(FileReference::class);
        $fileReferenceMock
            ->method('getOriginalFile')
            ->willReturn($originalFileMock);

        self::assertSame($metadata, $this->fileHandler->getMetadataFromFile($fileReferenceMock));
    }

    /**
     * Tests that getMetadataFromFile() resolves a ProcessedFile to its original File
     * and returns the metadata from that underlying file object.
     */
    #[Test]
    public function getMetadataFromFileReturnsMetadataForProcessedFile(): void
    {
        $metadata = ['uid' => 3, 'title' => 'Processed File'];

        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn($metadata);

        $originalFileMock = $this->createMock(File::class);
        $originalFileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        $processedFileMock = $this->createMock(ProcessedFile::class);
        $processedFileMock
            ->method('getOriginalFile')
            ->willReturn($originalFileMock);

        self::assertSame($metadata, $this->fileHandler->getMetadataFromFile($processedFileMock));
    }

    /**
     * Tests that getMetadataFromFile() returns an empty array when given a
     * FileInterface implementation that is not a File, FileReference, or
     * ProcessedFile, indicating the file type is not supported for metadata retrieval.
     */
    #[Test]
    public function getMetadataFromFileReturnsEmptyArrayForUnknownType(): void
    {
        $unknownFileMock = $this->createMock(FileInterface::class);

        self::assertSame([], $this->fileHandler->getMetadataFromFile($unknownFileMock));
    }

    /**
     * Tests that getMetadataUid() correctly resolves a FileReference by navigating
     * to its original File and returning the metadata UID from that file's metadata.
     */
    #[Test]
    public function getMetadataUidReturnsUidFromFileReference(): void
    {
        $metaDataMock = $this->createMock(MetaDataAspect::class);
        $metaDataMock
            ->method('get')
            ->willReturn(['uid' => 456]);

        $originalFileMock = $this->createMock(File::class);
        $originalFileMock
            ->method('getMetaData')
            ->willReturn($metaDataMock);

        $fileReferenceMock = $this->createMock(FileReference::class);
        $fileReferenceMock
            ->method('getOriginalFile')
            ->willReturn($originalFileMock);

        self::assertSame(456, $this->fileHandler->getMetadataUid($fileReferenceMock));
    }
}
