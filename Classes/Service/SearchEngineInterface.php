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
use TYPO3\CMS\Core\SingletonInterface;

/**
 * The interface that every search engine must implement.
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
     * Opens an index for processing.
     *
     * @param string $indexName The name of the index to open
     */
    public function indexOpen(string $indexName): void;

    /**
     * Closes the current opened index.
     */
    public function indexClose(): void;

    /**
     * Checks if an index exists or not.
     *
     * @param string $indexName The name of an index to check
     *
     * @return bool
     */
    public function indexExists(string $indexName): bool;

    /**
     * Deletes an index.
     *
     * @param string $indexName The name of the index to be deleted
     *
     * @return bool
     */
    public function indexDelete(string $indexName): bool;

    /**
     * Commit the current index.
     *
     * @return bool
     */
    public function indexCommit(): bool;

    /**
     * Moves or renames an index.
     *
     * @param string $indexName   The name of the index on which to perform the operation
     * @param string $destination The name of the destination index
     *
     * @return bool
     */
    public function indexMove(string $indexName, string $destination): bool;

    /**
     * Adds or replaces a record.
     *
     * @param Document $document The document to index
     *
     * @return bool
     */
    public function documentAdd(Document $document): bool;

    /**
     * Updates a record.
     *
     * @param Document $document The document to update
     *
     * @return bool
     */
    public function documentUpdate(Document $document): bool;

    /**
     * Deletes a record by it unique ID.
     *
     * @param string $objectId
     *
     * @return bool
     */
    public function documentDelete(string $objectId): bool;
}
