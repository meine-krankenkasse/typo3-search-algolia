<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use RuntimeException;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Search engines are responsible for the communication between TYPO3 and
 * external search services (like Algolia, Elasticsearch, Solr, etc.).
 * They handle all operations related to:
 *
 * - Creating and managing indices
 * - Adding, updating, and deleting documents in the search index
 * - Managing the lifecycle of indexing operations
 * - Providing a consistent API regardless of the underlying search technology
 *
 * Implementations of this interface should adapt the specific API of a search
 * service to this common interface, allowing the rest of the system to work
 * with any search engine without knowing the specific implementation details.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @api
 */
interface SearchEngineInterface extends SingletonInterface
{
    /**
     * This method implements the immutable pattern, returning a new instance
     * with the provided index name without modifying the original instance.
     * This allows for fluent method chaining and ensures thread safety.
     *
     * @param string $indexName The name of the index to use
     *
     * @return SearchEngineInterface A new instance with the specified index name
     */
    public function withIndexName(string $indexName): SearchEngineInterface;

    /**
     * This method prepares an index for read/write operations. It must be called
     * before performing any document operations on the index. After operations
     * are complete, indexClose() should be called to release any resources.
     *
     * @param string $indexName The name of the index to open
     *
     * @return void
     */
    public function indexOpen(string $indexName): void;

    /**
     * This method releases any resources associated with the currently open index.
     * It should be called after all operations on an index are complete.
     *
     * @return void
     */
    public function indexClose(): void;

    /**
     * This method verifies whether the specified index exists in the search engine.
     * It can be used to determine if an index needs to be created before use.
     *
     * @param string $indexName The name of the index to check
     *
     * @return bool True if the index exists, false otherwise
     */
    public function indexExists(string $indexName): bool;

    /**
     * This method permanently removes an index and all its documents from the
     * search engine. This operation cannot be undone, so it should be used with caution.
     *
     * @param string $indexName The name of the index to be deleted
     *
     * @return bool True if the deletion was successful, false otherwise
     */
    public function indexDelete(string $indexName): bool;

    /**
     * This method ensures that all changes made to the index are persisted
     * and made available for searching. Some search engines perform commits
     * automatically, while others require explicit commits.
     *
     * @return bool True if the commit was successful, false otherwise
     */
    public function indexCommit(): bool;

    /**
     * This method changes the name of an existing index. It can be used
     * for implementing zero-downtime reindexing by building a new index
     * and then swapping it with the live index.
     *
     * @param string $indexName   The name of the source index to be moved/renamed
     * @param string $destination The name of the destination index
     *
     * @return bool True if the move operation was successful, false otherwise
     */
    public function indexMove(string $indexName, string $destination): bool;

    /**
     * This method retrieves information about all available indices in the
     * search engine. The exact format of the returned data depends on the
     * specific search engine implementation.
     *
     * @return array<int|string, mixed> An array of index information
     */
    public function indexList(): array;

    /**
     * This method clears all documents from the specified index but keeps
     * the index structure intact. This is useful when you want to completely
     * rebuild the index contents without recreating the index settings.
     *
     * @param string $indexName The name of the index to clear
     *
     * @return bool True if the clear operation was successful, false otherwise
     */
    public function indexClear(string $indexName): bool;

    /**
     * This method adds a document to the search index. If a document with the
     * same ID already exists, it will be replaced. The document must contain
     * all required fields for the index.
     *
     * @param Document $document The document to index
     *
     * @return bool True if the document was successfully added, false otherwise
     *
     * @throws RuntimeException If no index is currently open
     */
    public function documentAdd(Document $document): bool;

    /**
     * Updates an existing document in the current index.
     *
     * This method updates a document in the search index. Depending on the
     * search engine implementation, this might be a partial update (only
     * changing specified fields) or a complete replacement.
     *
     * @param Document $document The document to update
     *
     * @return bool True if the document was successfully updated, false otherwise
     *
     * @throws RuntimeException If no index is currently open
     */
    public function documentUpdate(Document $document): bool;

    /**
     * Deletes a document from the current index by its unique ID.
     *
     * This method removes a document from the search index based on its
     * unique identifier. If the document doesn't exist, the method may
     * return false or true depending on the search engine implementation.
     *
     * @param string $documentId The unique identifier of the document to delete
     *
     * @return bool True if the document was successfully deleted, false otherwise
     *
     * @throws RuntimeException If no index is currently open
     */
    public function documentDelete(string $documentId): bool;

    /**
     * Removes a record from the search index.
     *
     * This is a high-level method that handles the complete process of removing
     * a record from the search index:
     * 1. Generates a unique document ID for the record
     * 2. Opens the appropriate index
     * 3. Deletes the document
     * 4. Commits the changes
     * 5. Closes the index
     *
     * @param string $tableName The database table name of the record
     * @param int    $recordUid The unique identifier of the record
     *
     * @return void
     *
     * @throws RuntimeException If no index name is set
     */
    public function deleteFromIndex(string $tableName, int $recordUid): void;
}
