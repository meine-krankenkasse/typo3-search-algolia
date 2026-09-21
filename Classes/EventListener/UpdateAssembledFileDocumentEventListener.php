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
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileRepository;

use function array_filter;
use function array_map;

/**
 * Event listener for enhancing file documents with file-specific information.
 *
 * This listener responds to AfterDocumentAssembledEvent events that are dispatched
 * after a file document has been assembled. It enhances the document by adding
 * various file-specific fields:
 * - File extension (for filtering by file type)
 * - MIME type (for proper content handling)
 * - File name (for display in search results)
 * - File size (for informational purposes)
 * - Public URL (for linking to the file)
 * - File content (extracted from the file for full-text search)
 *
 * For PDF files, this listener uses the PdfParser library to extract the text
 * content, which is then cleaned and normalized for indexing. This allows users
 * to search for text within PDF documents, not just their metadata.
 *
 * These enhancements make file search results more useful by providing both
 * the necessary metadata for display and filtering, as well as the actual
 * content for full-text search capabilities.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class UpdateAssembledFileDocumentEventListener
{
    /**
     * The default maximum size of the extracted content field, in bytes.
     *
     * Kept safely under the lowest record size limit search engines like
     * Algolia enforce, since the content field dominates record size for
     * indexed files.
     */
    private const int DEFAULT_MAX_CONTENT_BYTES = 8000;

    /**
     * Initializes the event listener with the file repository service.
     *
     * This constructor injects the TYPO3 FileRepository service that is used
     * to retrieve file objects based on their UIDs. The file repository is
     * essential for accessing the actual file data and content that needs to
     * be added to the document, including file properties like extension,
     * MIME type, name, size, and the file content itself for full-text indexing.
     *
     * @param FileRepository  $fileRepository  The TYPO3 file repository service
     * @param LoggerInterface $logger          The logger used to record content extraction failures
     * @param int             $maxContentBytes The maximum size of the extracted content field, in bytes
     */
    public function __construct(
        private FileRepository $fileRepository,
        private LoggerInterface $logger,
        private int $maxContentBytes = self::DEFAULT_MAX_CONTENT_BYTES,
    ) {
    }

    /**
     * Processes the document assembled event and enhances file documents.
     *
     * This method is automatically called by the event dispatcher when an
     * AfterDocumentAssembledEvent is dispatched. It performs the following tasks:
     *
     * 1. Verifies that the event is for a file document (using instanceof FileIndexer)
     * 2. Extracts the document and record data from the event
     * 3. Retrieves the file object using the FileRepository
     * 4. Adds various file-specific fields to the document:
     *    - File extension (for filtering by file type)
     *    - MIME type (for proper content handling)
     *    - File name (for display in search results)
     *    - File size (for informational purposes)
     *    - Public URL (for linking to the file, with special handling for local files)
     *    - File content (extracted from the file for full-text search)
     *
     * The file content extraction is delegated to the getFileContent method,
     * which handles PDF parsing and text normalization for proper indexing.
     *
     * @param AfterDocumentAssembledEvent $event The document assembled event containing the document and record data
     *
     * @return void
     */
    public function __invoke(AfterDocumentAssembledEvent $event): void
    {
        if (!($event->getIndexer() instanceof FileIndexer)) {
            return;
        }

        $document  = $event->getDocument();
        $record    = $event->getRecord();
        $sysFileId = $record['file'];

        try {
            $file = $this->fileRepository->findByUid($sysFileId);
        } catch (Exception) {
            $file = null;
        }

        if (!($file instanceof FileInterface)) {
            return;
        }

        // Add file-related fields
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

        $publicUrl = $file->getPublicUrl();

        // Remove the left leading slash
        if (
            ($publicUrl !== null)
            && str_starts_with($publicUrl, '/')
            && ($file->getStorage() !== null)
            && ($file->getStorage()->getDriverType() === 'Local')
        ) {
            $publicUrl = ltrim($publicUrl, '/');
        }

        $document->setField(
            'url',
            $publicUrl
        );

        $document->setField(
            'content',
            $this->getFileContent($file)
        );
    }

    /**
     * Extracts and processes the text content from a file for indexing.
     *
     * This helper method handles the extraction of text content from files
     * for full-text indexing. Currently, it only supports PDF files, using
     * the PdfParser library to extract text content. The method:
     *
     * 1. Checks if the file is a PDF (returns null for other file types)
     * 2. Configures the PDF parser with appropriate settings:
     *    - Disables image content retention to reduce memory usage
     *    - Clears horizontal offset to improve text extraction
     *    - Enables encryption ignoring to handle protected PDFs
     * 3. Parses the PDF content and extracts the text per page
     * 4. Removes lines that recur across most pages (e.g. a repeated title)
     * 5. Cleans the extracted text using ContentExtractor to normalize it
     * 6. Truncates the content to stay within the search engine's record size limit
     *
     * If any errors occur during parsing (e.g., corrupted PDF), the method
     * returns null, allowing the indexing process to continue without the
     * content rather than failing completely.
     *
     * @param FileInterface $file The file object to extract content from
     *
     * @return string|null The extracted and processed text content, or null if extraction failed or is not supported
     */
    private function getFileContent(FileInterface $file): ?string
    {
        // Currently, only PDF files are supported
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
            $pdf = $parser->parseContent($file->getContents());

            // Smalot\PdfParser\Document::getText() itself guards against a null
            // page entry ("In some cases, the $page variable may be null"), so
            // getPages() is not guaranteed to only ever return Page instances.
            // Not covered by a test: the installed smalot/pdfparser version
            // filters non-Page entries out of Pages::getPages() internally
            // before this code ever sees them, so a null entry is not
            // currently reproducible through a real PDF fixture. Re-check
            // with: grep -n 'instanceof (self|Page)'
            // vendor/smalot/pdfparser/src/Smalot/PdfParser/Pages.php
            $pageTexts = array_filter(
                array_map(
                    static fn (?Page $page): ?string => $page?->getText(),
                    $pdf->getPages()
                ),
                static fn (?string $pageText): bool => $pageText !== null
            );

            $content = ContentExtractor::removeRecurringLines($pageTexts);
            $content = ContentExtractor::sanitizeContent($content);
            $content = ContentExtractor::truncateToByteLength(
                $content,
                $this->maxContentBytes
            );
        } catch (Exception $exception) {
            $this->logger->warning(
                'Failed to extract PDF content for indexing',
                [
                    'file'      => $file->getIdentifier(),
                    'fileName'  => $file->getName(),
                    'exception' => $exception->getMessage(),
                ]
            );

            return null;
        }

        return $content !== '' ? $content : null;
    }
}
