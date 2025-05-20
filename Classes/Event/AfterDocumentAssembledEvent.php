<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;

/**
 * This event is triggered after the index document has been assembled and filled.
 *
 * The event is dispatched by the DocumentBuilder after a document has been assembled
 * with all its fields and metadata. Event listeners can modify the document before
 * it is sent to the search engine for indexing, allowing for:
 * - Adding additional fields to the document
 * - Modifying existing field values
 * - Performing content transformations or enrichment
 * - Adding custom metadata based on the record type
 *
 * This event is a key extension point for customizing the content that gets indexed
 * without modifying the core document assembly process.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class AfterDocumentAssembledEvent
{
    /**
     * The processed document that has been assembled.
     *
     * This property contains the document object that has been created and filled
     * with fields and metadata from the database record. Event listeners can access
     * this property to read or modify the document before it is sent to the search engine.
     *
     * @var Document
     */
    private Document $document;

    /**
     * The indexer instance that created the document.
     *
     * This property contains the indexer that was used to create the document.
     * It provides context about the type of content being indexed (pages, content elements,
     * files, etc.) and access to indexer-specific configuration and methods.
     *
     * @var IndexerInterface
     */
    private IndexerInterface $indexer;

    /**
     * The indexing service configuration used for this indexing operation.
     *
     * This property contains the configuration that defines how the content should
     * be indexed, including which search engine to use, which fields to include,
     * and other indexing parameters. Event listeners can use this information to
     * make decisions about how to modify the document.
     *
     * @var IndexingService
     */
    private IndexingService $indexingService;

    /**
     * The original database record that was used to create the document.
     *
     * This property contains the raw data from the database that was used to
     * create the document. Event listeners can access this property to retrieve
     * additional information from the record that might not have been included
     * in the document by default.
     *
     * @var array<string, mixed>
     */
    private array $record;

    /**
     * Constructor for the AfterDocumentAssembledEvent.
     *
     * Initializes a new event instance with the assembled document, the indexer that
     * created it, the indexing service configuration, and the original database record.
     * This event is typically dispatched by the DocumentBuilder after a document has
     * been assembled and before it is sent to the search engine.
     *
     * @param Document             $document        The assembled document with fields and metadata
     * @param IndexerInterface     $indexer         The indexer instance that created the document
     * @param IndexingService      $indexingService The indexing service configuration used
     * @param array<string, mixed> $record          The original database record data
     */
    public function __construct(
        Document $document,
        IndexerInterface $indexer,
        IndexingService $indexingService,
        array $record,
    ) {
        $this->document        = $document;
        $this->indexer         = $indexer;
        $this->indexingService = $indexingService;
        $this->record          = $record;
    }

    /**
     * Returns the assembled document.
     *
     * This method provides access to the document that has been assembled with fields
     * and metadata from the database record. Event listeners can use this method to
     * retrieve the document for reading or modification before it is sent to the
     * search engine for indexing.
     *
     * @return Document The assembled document with fields and metadata
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Returns the indexer instance that created the document.
     *
     * This method provides access to the indexer that was used to create the document.
     * Event listeners can use this method to retrieve information about the type of
     * content being indexed and to access indexer-specific configuration and methods.
     *
     * @return IndexerInterface The indexer instance that created the document
     */
    public function getIndexer(): IndexerInterface
    {
        return $this->indexer;
    }

    /**
     * Returns the indexing service configuration used for this indexing operation.
     *
     * This method provides access to the configuration that defines how the content
     * should be indexed. Event listeners can use this method to retrieve information
     * about which search engine to use, which fields to include, and other indexing
     * parameters to make decisions about how to modify the document.
     *
     * @return IndexingService The indexing service configuration used
     */
    public function getIndexingService(): IndexingService
    {
        return $this->indexingService;
    }

    /**
     * Returns the original database record that was used to create the document.
     *
     * This method provides access to the raw data from the database that was used
     * to create the document. Event listeners can use this method to retrieve
     * additional information from the record that might not have been included
     * in the document by default, allowing for custom field mapping or enrichment.
     *
     * @return array<string, mixed> The original database record data
     */
    public function getRecord(): array
    {
        return $this->record;
    }
}
