<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Model;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;

/**
 * Model representing a document to be indexed in the search engine.
 *
 * This class serves as a container for document data that will be sent to the search
 * engine for indexing. It provides methods for:
 * - Storing and retrieving document fields and their values
 * - Accessing the original record data from the TYPO3 database
 * - Referencing the indexer that created the document
 *
 * Documents are typically created by the DocumentBuilder during the indexing process
 * and then passed to a search engine service for actual indexing. The document
 * structure is flexible, allowing for different field sets based on the record type
 * and indexing configuration.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class Document
{
    /**
     * The document fields and their values.
     *
     * This property stores the actual fields that will be sent to the search engine
     * for indexing. Each field is represented as a key-value pair, where the key is
     * the field name and the value is the field content. The fields are populated
     * by the DocumentBuilder based on the record data and indexing configuration.
     *
     * @var array<string, mixed>
     */
    private array $fields = [];

    /**
     * Initializes a new document instance with the indexer and record data.
     *
     * This constructor creates a new document instance with the specified indexer
     * and record data. The indexer provides context about the type of content being
     * indexed, while the record data contains the raw information from the TYPO3
     * database that will be used to populate the document fields.
     *
     * The document fields themselves are not populated by the constructor; this is
     * typically done by the DocumentBuilder after creating the document instance.
     *
     * @param IndexerInterface     $indexer The indexer that created this document
     * @param array<string, mixed> $record  The original database record data
     */
    public function __construct(
        private readonly IndexerInterface $indexer,
        private readonly array $record,
    ) {
    }

    /**
     * Returns the indexer that created this document.
     *
     * This method provides access to the indexer that was used to create this document.
     * The indexer contains information about the type of content being indexed and
     * can be used to access indexer-specific configuration and methods.
     *
     * This is particularly useful for event listeners and other components that need
     * to know what type of content the document represents in order to process it
     * appropriately.
     *
     * @return IndexerInterface The indexer that created this document
     */
    public function getIndexer(): IndexerInterface
    {
        return $this->indexer;
    }

    /**
     * Returns the original database record data.
     *
     * This method provides access to the raw data from the TYPO3 database that was
     * used to create this document. It contains all fields from the original record
     * and can be used to retrieve additional information that might not be included
     * in the document fields.
     *
     * This is particularly useful for event listeners that need to access specific
     * record fields that weren't mapped to document fields by the DocumentBuilder.
     *
     * @return array<string, mixed> The original database record data
     */
    public function getRecord(): array
    {
        return $this->record;
    }

    /**
     * Returns all document fields and their values.
     *
     * This method provides access to all fields that will be sent to the search engine
     * for indexing. Each field is represented as a key-value pair, where the key is
     * the field name and the value is the field content.
     *
     * The fields are typically populated by the DocumentBuilder based on the record
     * data and indexing configuration, and may be further modified by event listeners
     * before the document is sent to the search engine.
     *
     * @return array<string, mixed> All document fields and their values
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Sets a field value in the document.
     *
     * This method adds or updates a field in the document with the specified name
     * and value. If the value is null, the field is removed from the document using
     * the removeField() method.
     *
     * The method follows the fluent interface pattern, returning the document instance
     * to allow for method chaining, which makes it convenient to set multiple fields
     * in a single statement.
     *
     * Fields set with this method will be included in the data sent to the search
     * engine when the document is indexed, allowing for customized field mappings
     * and additional metadata to be included in the search index.
     *
     * @param string $name  The field name to set
     * @param mixed  $value The value to assign to the field, or NULL to remove the field
     *
     * @return Document The current document instance for method chaining
     */
    public function setField(string $name, mixed $value): Document
    {
        if ($value === null) {
            $this->removeField($name);
        } else {
            $this->fields[$name] = $value;
        }

        return $this;
    }

    /**
     * Removes a field from the document.
     *
     * This method removes the specified field from the document if it exists.
     * If the field doesn't exist, the method has no effect.
     *
     * The method follows the fluent interface pattern, returning the document instance
     * to allow for method chaining, which makes it convenient to perform multiple
     * operations on the document in a single statement.
     *
     * Removing fields can be useful when:
     * - Cleaning up temporary fields that shouldn't be indexed
     * - Selectively excluding certain fields based on conditions
     * - Replacing a field with a different value by removing it first
     *
     * @param string $name The field name to remove
     *
     * @return Document The current document instance for method chaining
     */
    public function removeField(string $name): Document
    {
        if (isset($this->fields[$name])) {
            unset($this->fields[$name]);
        }

        return $this;
    }
}
