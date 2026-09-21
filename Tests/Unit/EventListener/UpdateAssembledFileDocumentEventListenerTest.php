<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener;

use Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\UpdateAssembledFileDocumentEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * Unit tests for UpdateAssembledFileDocumentEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(UpdateAssembledFileDocumentEventListener::class)]
#[UsesClass(AfterDocumentAssembledEvent::class)]
#[UsesClass(ContentExtractor::class)]
#[UsesClass(Document::class)]
class UpdateAssembledFileDocumentEventListenerTest extends TestCase
{
    /**
     * Tests that the listener does nothing when the indexer
     * is not a FileIndexer instance.
     */
    #[Test]
    public function invokeDoesNothingForNonFileIndexer(): void
    {
        $fileRepositoryMock = $this->createMock(FileRepository::class);
        $fileRepositoryMock->expects(self::never())
            ->method('findByUid');

        $indexerMock         = $this->createMock(PageIndexer::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);
        $record              = ['uid' => 42, 'file' => 1];
        $document            = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledFileDocumentEventListener($fileRepositoryMock, $this->createMock(LoggerInterface::class));
        $listener($event);

        self::assertEmpty($document->getFields());
    }

    /**
     * Tests that the listener adds file-specific fields (extension,
     * mimeType, name, size, url) to the document.
     */
    #[Test]
    public function invokeAddsFileFieldsToDocument(): void
    {
        $storageMock = $this->createMock(ResourceStorage::class);
        $storageMock->method('getDriverType')
            ->willReturn('Local');

        $fileMock = $this->createMock(File::class);
        $fileMock->method('getExtension')->willReturn('pdf');
        $fileMock->method('getMimeType')->willReturn('application/pdf');
        $fileMock->method('getName')->willReturn('test-document.pdf');
        $fileMock->method('getSize')->willReturn(12345);
        $fileMock->method('getPublicUrl')->willReturn('/fileadmin/test-document.pdf');
        $fileMock->method('getStorage')->willReturn($storageMock);
        // Non-pdf returns null for content
        $fileMock->method('getContents')->willReturn('');

        $fileRepositoryMock = $this->createMock(FileRepository::class);
        $fileRepositoryMock->method('findByUid')
            ->with(5)
            ->willReturn($fileMock);

        $indexerMock = $this->createMock(FileIndexer::class);
        $indexerMock->method('getTable')
            ->willReturn('sys_file_metadata');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $record   = ['uid' => 42, 'file' => 5];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledFileDocumentEventListener($fileRepositoryMock, $this->createMock(LoggerInterface::class));
        $listener($event);

        self::assertSame('pdf', $document->getFields()['extension']);
        self::assertSame('application/pdf', $document->getFields()['mimeType']);
        self::assertSame('test-document.pdf', $document->getFields()['name']);
        self::assertSame(12345, $document->getFields()['size']);
        self::assertSame('fileadmin/test-document.pdf', $document->getFields()['url']);
    }

    /**
     * Tests that the listener returns early without modifying
     * the document when the file repository throws an exception.
     */
    #[Test]
    public function invokeReturnsEarlyWhenFileNotFound(): void
    {
        $fileRepositoryMock = $this->createMock(FileRepository::class);
        $fileRepositoryMock->method('findByUid')
            ->with(999)
            ->willThrowException(new Exception('File not found'));

        $indexerMock         = $this->createMock(FileIndexer::class);
        $indexingServiceMock = $this->createMock(IndexingService::class);

        $record   = ['uid' => 42, 'file' => 999];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledFileDocumentEventListener($fileRepositoryMock, $this->createMock(LoggerInterface::class));
        $listener($event);

        self::assertEmpty($document->getFields());
    }

    /**
     * Tests that the listener does not set a content field
     * for non-PDF files.
     */
    #[Test]
    public function invokeReturnsNullContentForNonPdfFile(): void
    {
        $storageMock = $this->createMock(ResourceStorage::class);
        $storageMock->method('getDriverType')
            ->willReturn('Local');

        $fileMock = $this->createMock(File::class);
        $fileMock->method('getExtension')->willReturn('docx');
        $fileMock->method('getMimeType')->willReturn('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $fileMock->method('getName')->willReturn('document.docx');
        $fileMock->method('getSize')->willReturn(5000);
        $fileMock->method('getPublicUrl')->willReturn('/fileadmin/document.docx');
        $fileMock->method('getStorage')->willReturn($storageMock);

        $fileRepositoryMock = $this->createMock(FileRepository::class);
        $fileRepositoryMock->method('findByUid')
            ->with(10)
            ->willReturn($fileMock);

        $indexerMock = $this->createMock(FileIndexer::class);
        $indexerMock->method('getTable')
            ->willReturn('sys_file_metadata');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $record   = ['uid' => 42, 'file' => 10];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        $listener = new UpdateAssembledFileDocumentEventListener($fileRepositoryMock, $this->createMock(LoggerInterface::class));
        $listener($event);

        // Content is null for non-PDF files, so the field should not exist (setField with null removes the field)
        self::assertArrayNotHasKey('content', $document->getFields());
    }

    /**
     * Builds an AfterDocumentAssembledEvent, its Document, and a matching
     * FileRepository mock for a PDF file with the given identity and raw
     * PDF bytes, the setup every PDF-content extraction test needs.
     *
     * @param int    $fileUid     The sys_file UID the event's record refers to
     * @param string $fileName    The file name reported by the mocked File
     * @param int    $fileSize    The file size reported by the mocked File
     * @param string $pdfContents The raw PDF bytes returned by the mocked File's getContents()
     *
     * @return array{0: AfterDocumentAssembledEvent, 1: Document, 2: MockObject&FileRepository}
     */
    private function createPdfFileEvent(int $fileUid, string $fileName, int $fileSize, string $pdfContents): array
    {
        $storageMock = self::createStub(ResourceStorage::class);
        $storageMock->method('getDriverType')->willReturn('Local');

        $fileMock = self::createStub(File::class);
        $fileMock->method('getExtension')->willReturn('pdf');
        $fileMock->method('getMimeType')->willReturn('application/pdf');
        $fileMock->method('getName')->willReturn($fileName);
        $fileMock->method('getSize')->willReturn($fileSize);
        $fileMock->method('getPublicUrl')->willReturn('/fileadmin/' . $fileName);
        $fileMock->method('getStorage')->willReturn($storageMock);
        $fileMock->method('getContents')->willReturn($pdfContents);

        $fileRepositoryMock = $this->createMock(FileRepository::class);
        $fileRepositoryMock->method('findByUid')
            ->with($fileUid)
            ->willReturn($fileMock);

        $indexerMock = self::createStub(FileIndexer::class);
        $indexerMock->method('getTable')->willReturn('sys_file_metadata');

        $indexingServiceMock = self::createStub(IndexingService::class);

        $record   = ['uid' => 42, 'file' => $fileUid];
        $document = new Document($indexerMock, $record);

        $event = new AfterDocumentAssembledEvent(
            $document,
            $indexerMock,
            $indexingServiceMock,
            $record
        );

        return [$event, $document, $fileRepositoryMock];
    }

    /**
     * Tests that the listener strips a line that recurs on every page of a
     * PDF (a repeated document title acting as a header) from the extracted
     * content field, while keeping each page's unique body text.
     */
    #[Test]
    public function invokeStripsRecurringHeaderFromPdfContent(): void
    {
        [$event, $document, $fileRepositoryMock] = $this->createPdfFileEvent(
            7,
            'satzung.pdf',
            1711,
            (string) file_get_contents(__DIR__ . '/Fixtures/pdf-with-repeated-header.pdf')
        );

        $listener = new UpdateAssembledFileDocumentEventListener(
            $fileRepositoryMock,
            self::createStub(LoggerInterface::class)
        );
        $listener($event);

        $content = $document->getFields()['content'];

        self::assertStringNotContainsString('Satzung der Krankenkasse', $content);
        self::assertStringContainsString('Page 1 unique content marker.', $content);
        self::assertStringContainsString('Page 2 unique content marker.', $content);
        self::assertStringContainsString('Page 3 unique content marker.', $content);
        self::assertStringContainsString('Page 4 unique content marker.', $content);
    }

    /**
     * Tests that the listener correctly extracts and strips a recurring
     * header from a real-world-shaped PDF: FlateDecode-compressed content
     * streams and German umlaut text (WinAnsiEncoding), as a typical export
     * (word processor, scanner OCR) would produce, rather than the plain
     * uncompressed ASCII streams the other fixtures use.
     */
    #[Test]
    public function invokeHandlesCompressedPdfWithUmlautsCorrectly(): void
    {
        [$event, $document, $fileRepositoryMock] = $this->createPdfFileEvent(
            9,
            'satzung-umlaute.pdf',
            1481,
            (string) file_get_contents(__DIR__ . '/Fixtures/pdf-with-umlauts-compressed.pdf')
        );

        $listener = new UpdateAssembledFileDocumentEventListener(
            $fileRepositoryMock,
            self::createStub(LoggerInterface::class)
        );
        $listener($event);

        $content = $document->getFields()['content'];

        self::assertTrue(mb_check_encoding($content, 'UTF-8'), 'Extracted content must be valid UTF-8');
        self::assertStringNotContainsString('Satzung der Krankenkasse für Versicherte', $content);
        self::assertStringContainsString('Übersicht enthält wichtige Informationen für Mitglieder.', $content);
        self::assertStringContainsString('Änderungen der Beiträge werden hier erläutert.', $content);
        self::assertStringContainsString('Zusätzliche Leistungen für Familienangehörige sind möglich.', $content);
    }

    /**
     * Tests that the listener truncates extracted PDF content that exceeds
     * the configured maximum size, so the document still gets indexed
     * instead of being rejected by the search engine as too big.
     */
    #[Test]
    public function invokeTruncatesContentExceedingConfiguredByteLimit(): void
    {
        [$event, $document, $fileRepositoryMock] = $this->createPdfFileEvent(
            8,
            'long-document.pdf',
            826,
            (string) file_get_contents(__DIR__ . '/Fixtures/pdf-exceeding-content-limit.pdf')
        );

        // The fixture extracts to well over 50 bytes of text, so a 50-byte
        // limit forces truncation without needing an oversized fixture.
        $listener = new UpdateAssembledFileDocumentEventListener(
            $fileRepositoryMock,
            self::createStub(LoggerInterface::class),
            50
        );
        $listener($event);

        $content = $document->getFields()['content'];

        self::assertLessThanOrEqual(50, strlen($content));
        self::assertTrue(mb_check_encoding($content, 'UTF-8'));
    }
}
