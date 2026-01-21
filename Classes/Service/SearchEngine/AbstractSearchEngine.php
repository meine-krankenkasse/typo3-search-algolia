<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

/**
 * Abstract base class for search engine implementations.
 *
 * This abstract class provides a foundation for implementing search engines
 * by defining common functionality and enforcing the SearchEngineInterface.
 * It handles basic operations like setting the index name and deleting records
 * from the index, while leaving specific implementation details to child classes.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractSearchEngine implements SearchEngineInterface
{
    /**
     * The name of the currently active index.
     *
     * This property stores the name of the index that is currently being operated on.
     * It is set when opening an index and cleared when closing it.
     */
    protected ?string $indexName = null;

    /**
     * Constructor for the abstract search engine.
     *
     * Initializes the search engine with an event dispatcher that will be used
     * for various operations like creating unique document IDs.
     *
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher service
     */
    public function __construct(
        protected EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Creates a new instance of the search engine with the specified index name.
     *
     * This method implements the immutable pattern by creating a clone of the current
     * search engine instance with a different index name. This allows for fluent
     * method chaining without modifying the original instance.
     *
     * @param string $indexName The name of the index to use
     *
     * @return SearchEngineInterface A new instance with the specified index name
     */
    #[Override]
    public function withIndexName(string $indexName): SearchEngineInterface
    {
        $clone            = clone $this;
        $clone->indexName = $indexName;

        return $clone;
    }

    /**
     * Deletes a record from the search index.
     *
     * This method handles the complete process of removing a record from the search index:
     *
     * 1. Generates a unique document ID for the record using the event dispatcher
     * 2. Opens the index
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
    #[Override]
    public function deleteFromIndex(string $tableName, int $recordUid): void
    {
        if ($this->indexName === null) {
            throw new RuntimeException('Index name not set. Use "indexOpen" method.');
        }

        /** @var CreateUniqueDocumentIdEvent $documentIdEvent */
        $documentIdEvent = $this->eventDispatcher
            ->dispatch(
                new CreateUniqueDocumentIdEvent(
                    $this,
                    $tableName,
                    $recordUid
                )
            );

        // Remove record in search engine index
        $this->indexOpen($this->indexName);
        $this->documentDelete($documentIdEvent->getDocumentId());
        $this->indexCommit();
        $this->indexClose();
    }
}
