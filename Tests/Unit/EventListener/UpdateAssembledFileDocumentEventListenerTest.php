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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\UpdateAssembledFileDocumentEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
class UpdateAssembledFileDocumentEventListenerTest extends TestCase
{
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
}
