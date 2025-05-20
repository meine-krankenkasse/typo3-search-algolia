<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;

/**
 * This event is triggered when a unique document ID needs to be created for a record in the search engine.
 *
 * The event is dispatched by search engine implementations when they need to generate
 * a unique identifier for a document in the search index. This typically happens when:
 * - Adding a new document to the search index
 * - Updating an existing document in the search index
 * - Removing a document from the search index
 *
 * Event listeners can set the document ID by calling the setDocumentId() method.
 * If no listener sets a document ID, the search engine will typically use a default
 * format like "{table_name}_{record_uid}".
 *
 * This event allows for customizing how document IDs are generated without modifying
 * the core search engine implementation, which is useful for:
 * - Implementing custom ID formats for specific record types
 * - Ensuring compatibility with external systems that expect specific ID formats
 * - Adding prefixes or suffixes to IDs for namespacing or versioning
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class CreateUniqueDocumentIdEvent
{
    /**
     * The search engine instance that needs the document ID.
     *
     * This property contains the search engine that is requesting a unique document ID.
     * It provides context about which search engine implementation is being used
     * (e.g., Algolia) and access to search engine-specific configuration and methods.
     *
     * @var SearchEngineInterface
     */
    private readonly SearchEngineInterface $searchEngine;

    /**
     * The database table name of the record.
     *
     * This property contains the name of the database table that the record belongs to
     * (e.g., "pages", "tt_content", "sys_file_metadata"). It is used to identify the
     * type of content being indexed and is typically included in the document ID.
     *
     * @var string
     */
    private readonly string $tableName;

    /**
     * The unique identifier of the record.
     *
     * This property contains the UID of the database record that is being indexed.
     * It uniquely identifies the record within its table and is typically included
     * in the document ID to ensure uniqueness.
     *
     * @var int
     */
    private readonly int $recordUid;

    /**
     * The generated document ID.
     *
     * This property stores the document ID that will be used to identify the document
     * in the search engine index. It is initially empty and should be set by an event
     * listener using the setDocumentId() method. If no listener sets a document ID,
     * the search engine will typically use a default format.
     *
     * @var string
     */
    private string $documentId = '';

    /**
     * Constructor for the CreateUniqueDocumentIdEvent.
     *
     * Initializes a new event instance with the search engine that needs a document ID,
     * the table name and record UID that identify the record being indexed. The document ID
     * is initially empty and should be set by an event listener using the setDocumentId() method.
     *
     * This event is typically dispatched by search engine implementations when they need
     * to generate a unique identifier for a document in the search index.
     *
     * @param SearchEngineInterface $searchEngine The search engine requesting the document ID
     * @param string                $tableName    The database table name of the record
     * @param int                   $recordUid    The unique identifier of the record
     */
    public function __construct(
        SearchEngineInterface $searchEngine,
        string $tableName,
        int $recordUid,
    ) {
        $this->searchEngine = $searchEngine;
        $this->tableName    = $tableName;
        $this->recordUid    = $recordUid;
    }

    /**
     * Returns the search engine instance that needs the document ID.
     *
     * This method provides access to the search engine that is requesting a unique
     * document ID. Event listeners can use this method to retrieve information about
     * which search engine implementation is being used and to access search engine-specific
     * configuration and methods if needed for document ID generation.
     *
     * @return SearchEngineInterface The search engine requesting the document ID
     */
    public function getSearchEngine(): SearchEngineInterface
    {
        return $this->searchEngine;
    }

    /**
     * Returns the database table name of the record.
     *
     * This method provides access to the name of the database table that the record
     * belongs to. Event listeners can use this method to retrieve the table name for
     * inclusion in the document ID or to make decisions about how to format the ID
     * based on the type of content being indexed.
     *
     * @return string The database table name of the record
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Returns the unique identifier of the record.
     *
     * This method provides access to the UID of the database record that is being
     * indexed. Event listeners can use this method to retrieve the record UID for
     * inclusion in the document ID to ensure uniqueness.
     *
     * @return int The unique identifier of the record
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * Returns the generated document ID.
     *
     * This method provides access to the document ID that will be used to identify
     * the document in the search engine index. If no event listener has set a document ID
     * using the setDocumentId() method, this will return an empty string, indicating
     * that the search engine should use a default format.
     *
     * @return string The document ID or an empty string if none has been set
     */
    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    /**
     * Sets the document ID to be used for this record in the search engine.
     *
     * This method allows event listeners to set a custom document ID for the record
     * being indexed. The document ID should be unique within the search engine index
     * to avoid conflicts. Event listeners should use this method to implement custom
     * ID generation logic based on the record type, search engine, or other factors.
     *
     * The method returns the event instance to allow for method chaining in event listeners.
     *
     * @param string $documentId The unique document ID to use for this record
     *
     * @return CreateUniqueDocumentIdEvent The current event instance for method chaining
     */
    public function setDocumentId(string $documentId): CreateUniqueDocumentIdEvent
    {
        $this->documentId = $documentId;

        return $this;
    }
}
