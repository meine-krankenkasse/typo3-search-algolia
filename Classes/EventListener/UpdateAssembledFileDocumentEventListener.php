<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Class UpdateAssembledFileDocumentEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class UpdateAssembledFileDocumentEventListener
{
    /**
     * @var FileRepository
     */
    private readonly FileRepository $fileRepository;

    /**
     * Constructor.
     *
     * @param FileRepository $fileRepository
     */
    public function __construct(
        FileRepository $fileRepository,
    ) {
        $this->fileRepository = $fileRepository;
    }

    /**
     * Invoke the event listener.
     *
     * @param AfterDocumentAssembledEvent $event
     */
    public function __invoke(AfterDocumentAssembledEvent $event): void
    {
        if (!($event->getIndexer() instanceof FileIndexer)) {
            return;
        }

        $document  = $event->getDocument();
        $record    = $event->getRecord();
        $sysFileId = $record['file'];

        /** @var FileInterface $file */
        $file = $this->fileRepository->findByUid($sysFileId);

        // Add file related fields
        $document->setField(
            'extension',
            $file->getExtension()
        );

        $document->setField(
            'mimeType',
            $file->getMimeType()
        );

        $document->setField(
            'name',
            $file->getName()
        );

        $document->setField(
            'size',
            $file->getSize()
        );

        if ($file instanceof FileInterface) {
            $publicUrl = $file->getPublicUrl();

            // Remove left leading slash
            if (
                ($publicUrl !== null)
                && str_starts_with($publicUrl, '/')
                && ($file->getStorage()->getDriverType() === 'Local')
            ) {
                $publicUrl = ltrim($publicUrl, '/');
            }

            $document->setField(
                'url',
                $publicUrl
            );
        }

        $document->setField(
            'content',
            $this->getFileContent($file)
        );
    }

    /**
     * Returns the PDF file content as string.
     *
     * @param FileInterface $file
     *
     * @return string|null
     */
    private function getFileContent(FileInterface $file): ?string
    {
        // Currently only PDF files are supported
        if ($file->getExtension() !== 'pdf') {
            return null;
        }

        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setHorizontalOffset('');
        $config->setIgnoreEncryption(true);

        $parser = new Parser([], $config);

        // Parse the PDF file content
        try {
            $pdf     = $parser->parseContent($file->getContents());
            $content = ContentExtractor::cleanHtml($pdf->getText());
        } catch (Exception) {
            // TODO Track indexing errors and display failed records in backend

            return null;
        }

        // Prevent "json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded"
        $content = mb_convert_encoding(
            $content,
            mb_detect_encoding($content),
            'UTF-8'
        );

        return $content !== '' ? $content : null;
    }
}
