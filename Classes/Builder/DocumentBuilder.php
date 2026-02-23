<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Builder;

use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_scalar;

/**
 * This class is responsible for transforming database records into document
 * objects that can be indexed by search engines. It handles:
 *
 * - Creating document objects with appropriate metadata
 * - Adding standard fields like uid, pid, type, and timestamps
 * - Adding custom fields based on TypoScript configuration
 * - Cleaning HTML content for better search results
 * - Dispatching events to allow further document customization
 *
 * The builder follows the fluent interface pattern, allowing method chaining
 * for convenient document creation and configuration.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class DocumentBuilder
{
    /**
     * This property holds the document instance that is being assembled
     * with fields and metadata from the database record.
     */
    private Document $document;

    /**
     * Contains settings for how documents should be indexed, including
     * which search engine to use and other indexing parameters.
     */
    private IndexingService $indexingService;

    /**
     * Provides information about the table being indexed and other
     * type-specific indexing behavior.
     */
    private ?IndexerInterface $indexer = null;

    /**
     * Contains all fields and values from the database record that
     * will be used to populate the document.
     *
     * @var array<string, mixed>
     */
    private array $record = [];

    /**
     * Initializes the builder with required dependencies for event handling and TypoScript
     * configuration access. These services are used during the document assembly process.
     *
     * @param EventDispatcherInterface $eventDispatcher   Event dispatcher for document-related events
     * @param TypoScriptService        $typoScriptService Service for accessing TypoScript configuration
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TypoScriptService $typoScriptService,
    ) {
    }

    /**
     * The indexer provides information about the table being indexed and
     * type-specific indexing behavior.
     *
     * @param IndexerInterface|null $indexer The indexer responsible for the current record type
     *
     * @return DocumentBuilder The current builder instance for method chaining
     */
    public function setIndexer(?IndexerInterface $indexer): DocumentBuilder
    {
        $this->indexer = $indexer;

        return $this;
    }

    /**
     * This method stores the database record that will be used as the source
     * for document fields and metadata. It follows the fluent interface
     * pattern, allowing method chaining.
     *
     * @param array<string, mixed> $record The database record with fields and values
     *
     * @return DocumentBuilder The current builder instance for method chaining
     */
    public function setRecord(array $record): DocumentBuilder
    {
        $this->record = $record;

        return $this;
    }

    /**
     * The indexing service contains settings for how documents should be indexed,
     * including which search engine to use and other indexing parameters.
     * This method follows the fluent interface pattern, allowing method chaining.
     *
     * @param IndexingService $indexingService The indexing service configuration
     *
     * @return DocumentBuilder The current builder instance for method chaining
     */
    public function setIndexingService(IndexingService $indexingService): DocumentBuilder
    {
        $this->indexingService = $indexingService;

        return $this;
    }

    /**
     * This method provides access to the document that has been built
     * by the assemble() method. It should be called after assemble()
     * to retrieve the final document for indexing.
     *
     * @return Document The fully assembled document with all fields and metadata
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * This is the main method of the builder that performs the document assembly process:
     *
     * 1. Creates a new document instance
     * 2. Adds standard fields (uid, pid, type, indexed timestamp)
     * 3. Adds creation and modification timestamps if available
     * 4. Adds custom fields based on TypoScript configuration
     * 5. Dispatches an event to allow further document customization
     *
     * @return DocumentBuilder The current builder instance for method chaining
     */
    public function assemble(): DocumentBuilder
    {
        $indexer = $this->indexer;

        if (!($indexer instanceof IndexerInterface)) {
            return $this;
        }

        $this->document = GeneralUtility::makeInstance(
            Document::class,
            $indexer,
            $this->record
        );

        $tableName = $indexer->getTable();

        // Set common fields
        $this->document
            ->setField('uid', $this->record['uid'])
            ->setField('pid', $this->record['pid'] ?? 0)
            ->setField('type', $tableName)
            ->setField('indexed', time());

        // Add created at timestamp
        if (
            isset($GLOBALS['TCA'][$tableName]['ctrl']['crdate'])
            && ($GLOBALS['TCA'][$tableName]['ctrl']['crdate'] !== '')
        ) {
            $this->document
                ->setField(
                    'created',
                    $this->record[$GLOBALS['TCA'][$tableName]['ctrl']['crdate']]
                );
        }

        // Add changed at timestamp
        if (
            isset($GLOBALS['TCA'][$tableName]['ctrl']['tstamp'])
            && ($GLOBALS['TCA'][$tableName]['ctrl']['tstamp'] !== '')
        ) {
            $this->document
                ->setField(
                    'changed',
                    $this->record[$GLOBALS['TCA'][$tableName]['ctrl']['tstamp']]
                );
        }

        // Fill the document with configured fields for the indexer type
        $this->addConfiguredFieldsToDocument();

        $this->eventDispatcher
            ->dispatch(
                new AfterDocumentAssembledEvent(
                    $this->document,
                    $indexer,
                    $this->indexingService,
                    $this->record
                )
            );

        return $this;
    }

    /**
     * This method processes the database record and adds fields to the document
     * based on the TypoScript configuration. It:
     *
     * 1. Retrieves the field mapping from TypoScript
     * 2. Iterates through all fields in the record
     * 3. Checks if each field is configured for indexing
     * 4. Skips empty or null values
     * 5. Cleans HTML content from field values
     * 6. Adds the field to the document with the configured name
     *
     * The field mapping is defined in TypoScript under:
     *
     *   "module.tx_typo3searchalgolia.indexer.<TABLE_NAME>.fields.<FIELD_NAME>"
     *
     * @return void
     */
    private function addConfiguredFieldsToDocument(): void
    {
        if (!($this->indexer instanceof IndexerInterface)) {
            return;
        }

        $indexerType  = $this->indexer->getTable();
        $fieldMapping = $this->typoScriptService->getFieldMappingByType($indexerType);

        foreach ($this->record as $recordFieldName => $recordValue) {
            if (!isset($fieldMapping[$recordFieldName])) {
                continue;
            }

            // Only allow scalars.
            if (!is_scalar($recordValue)) {
                continue;
            }

            // Force the value to a string for consistency. $recordValue should never be boolean.
            $stringValue = (string) $recordValue;

            // Skip empty strings.
            if ($stringValue === '') {
                continue;
            }

            $this->document->setField(
                $fieldMapping[$recordFieldName],
                ContentExtractor::sanitizeContent($stringValue)
            );
        }
    }
}
