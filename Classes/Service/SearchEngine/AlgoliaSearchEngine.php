<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Model\Search\DeletedAtResponse;
use Algolia\AlgoliaSearch\Model\Search\ListIndicesResponse;
use Algolia\AlgoliaSearch\Model\Search\OperationIndexParams;
use Algolia\AlgoliaSearch\Model\Search\OperationType;
use Algolia\AlgoliaSearch\Model\Search\SaveObjectResponse;
use Algolia\AlgoliaSearch\Model\Search\UpdatedAtResponse;
use Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\MissingConfigurationException;
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\RateLimitException;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function is_array;

/**
 * This class provides the integration with Algolia's search service.
 * It handles all operations related to indices and documents, including:
 *
 * - Creating and managing indices
 * - Adding, updating, and deleting documents
 * - Moving indices between environments
 * - Clearing indices
 *
 * The class requires valid Algolia credentials (appId and apiKey) to be
 * configured in the TYPO3 extension configuration.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AlgoliaSearchEngine extends AbstractSearchEngine
{
    /**
     * Algolia search client instance.
     *
     * This property holds the connection to the Algolia API and is used
     * for all operations that interact with the Algolia search service.
     *
     * @var SearchClient
     */
    private SearchClient $client;

    /**
     * Algolia application ID.
     *
     * This property stores the unique identifier for the Algolia application
     * that this search engine will connect to. It is retrieved from the
     * extension configuration.
     *
     * @var string
     */
    private readonly string $appId;

    /**
     * Algolia API key.
     *
     * This property stores the authentication key used to access the Algolia API.
     * It is retrieved from the extension configuration and should be kept secure
     * as it provides write access to the Algolia indices.
     *
     * @var string
     */
    private readonly string $apiKey;

    /**
     * Constructor for the Algolia search engine.
     *
     * Initializes the search engine with required dependencies and retrieves
     * the Algolia credentials from the TYPO3 extension configuration. It validates
     * that the required configuration values (appId and apiKey) are present and
     * initializes the Algolia client.
     *
     * @param EventDispatcherInterface $eventDispatcher        Event dispatcher for handling events
     * @param ExtensionConfiguration   $extensionConfiguration TYPO3 extension configuration service
     *
     * @throws MissingConfigurationException If the Algolia credentials are missing or invalid
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct($eventDispatcher);

        try {
            $configuration = $extensionConfiguration->get(Constants::EXTENSION_NAME);
        } catch (Exception) {
            $configuration = [];
        }

        if (($configuration['appId'] === false)
            || ($configuration['apiKey'] === false)
        ) {
            throw new MissingConfigurationException(
                'Please provide a valid application ID and API key for the Algolia Search Engine.',
                1743580689
            );
        }

        $this->appId  = $configuration['appId'] ?? '';
        $this->apiKey = $configuration['apiKey'] ?? '';

        // @extensionScannerIgnoreLine
        $this->init();
    }

    /**
     * Initializes the Algolia search client.
     *
     * This method creates a new instance of the Algolia SearchClient using
     * the configured application ID and API key. It must be called before
     * any other methods that interact with the Algolia API.
     *
     * @return bool True if initialization was successful
     */
    public function init(): bool
    {
        $this->client = SearchClient::create(
            $this->appId,
            $this->apiKey
        );

        return true;
    }

    /**
     * Opens an index for operations.
     *
     * This method sets the current index name to be used for subsequent
     * document operations. It must be called before adding, updating,
     * or deleting documents.
     *
     * @param string $indexName The name of the index to open
     *
     * @return void
     */
    #[Override]
    public function indexOpen(string $indexName): void
    {
        $this->indexName = $indexName;
    }

    /**
     * Closes the currently open index.
     *
     * This method resets the current index name, effectively closing
     * the index for operations. After calling this method, no document
     * operations can be performed until indexOpen is called again.
     *
     * @return void
     */
    #[Override]
    public function indexClose(): void
    {
        $this->indexName = null;
    }

    /**
     * Checks if an index exists in Algolia.
     *
     * This method verifies whether the specified index exists in the
     * Algolia application. It handles any exceptions that might occur
     * during the API call and returns false in case of errors.
     *
     * @param string $indexName The name of the index to check
     *
     * @return bool True if the index exists, false otherwise
     */
    #[Override]
    public function indexExists(string $indexName): bool
    {
        try {
            return $this->client
                ->indexExists($indexName);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Deletes an index from Algolia.
     *
     * This method removes the specified index and all its documents from
     * the Algolia application. The operation is permanent and cannot be
     * undone, so use with caution.
     *
     * @param string $indexName The name of the index to delete
     *
     * @return bool True if the deletion was successful, false otherwise
     */
    #[Override]
    public function indexDelete(string $indexName): bool
    {
        $responseData = $this->client
            ->deleteIndex($indexName);

        if (is_array($responseData)) {
            $responseData = new DeletedAtResponse($responseData);
        }

        return $responseData
            ->valid();
    }

    /**
     * Commits any pending changes to the current index.
     *
     * In Algolia, changes are automatically committed as they are made,
     * so this method exists primarily for compatibility with the search
     * engine interface. It performs validation to ensure the client is
     * properly initialized and connected.
     *
     * @return bool True if the client is properly initialized and connected
     */
    #[Override]
    public function indexCommit(): bool
    {
        return true;
    }

    /**
     * Moves (renames) an index to a new name in Algolia.
     *
     * This method renames an existing index to a new name. It's useful for
     * implementing zero-downtime reindexing strategies where you build a new
     * index and then atomically replace the old one.
     *
     * @param string $indexName   The name of the source index
     * @param string $destination The new name for the index
     *
     * @return bool True if the move operation was successful, false otherwise
     */
    #[Override]
    public function indexMove(string $indexName, string $destination): bool
    {
        $responseData = $this->client
            ->operationIndex(
                $indexName,
                (new OperationIndexParams())
                    ->setOperation((new OperationType())::MOVE)
                    ->setDestination($destination)
            );

        if (is_array($responseData)) {
            $responseData = new UpdatedAtResponse($responseData);
        }

        return $responseData
            ->valid();
    }

    /**
     * Lists all indices in the Algolia application.
     *
     * This method retrieves information about all indices available in the
     * current Algolia application. It's useful for administrative purposes
     * and for getting an overview of the search infrastructure.
     *
     * @return array<int|string, mixed> An array of index information objects
     */
    #[Override]
    public function indexList(): array
    {
        $responseData = $this->client
            ->listIndices();

        if ($responseData instanceof ListIndicesResponse) {
            return $responseData->getItems();
        }

        return $responseData;
    }

    /**
     * Clears all objects from the specified index.
     *
     * This method removes all objects from the given index in the search engine.
     * It ensures the operation is executed successfully and validates the response.
     * If a rate limit is encountered, a RateLimitException is thrown.
     *
     * @param string $indexName The name of the index to be cleared
     *
     * @return bool True if the index is cleared successfully, otherwise false
     */
    #[Override]
    public function indexClear(string $indexName): bool
    {
        try {
            $responseData = $this->client
                ->clearObjects($indexName);

            if (is_array($responseData)) {
                $responseData = new UpdatedAtResponse($responseData);
            }

            return $responseData->valid();
        } catch (Exception $exception) {
            // Rate limit
            if ($exception->getCode() === 429) {
                throw new RateLimitException($exception->getMessage(), $exception->getCode());
            }
        }

        return false;
    }

    /**
     * Retrieves a unique identifier for the given document.
     *
     * This method generates a unique document ID by dispatching an event
     * to handle the creation of the identifier using the document's table
     * and its record data.
     *
     * @param Document $document the document object for which the unique ID is generated
     *
     * @return string the unique identifier for the document
     */
    private function getUniqueDocumentId(Document $document): string
    {
        /** @var CreateUniqueDocumentIdEvent $documentIdEvent */
        $documentIdEvent = $this->eventDispatcher
            ->dispatch(
                new CreateUniqueDocumentIdEvent(
                    $this,
                    $document->getIndexer()->getTable(),
                    $document->getRecord()['uid']
                )
            );

        return $documentIdEvent->getDocumentId();
    }

    /**
     * Adds a document to the current Algolia index.
     *
     * This method adds a new document to the currently open index. It automatically
     * generates a unique object ID for the document based on its content and adds
     * it to the document fields before saving. The index must be opened with
     * indexOpen() before calling this method.
     *
     * @param Document $document The document to add to the index
     *
     * @return bool True if the document was successfully added, false otherwise
     *
     * @throws RuntimeException If no index is currently open
     */
    #[Override]
    public function documentAdd(Document $document): bool
    {
        if ($this->indexName === null) {
            throw new RuntimeException('Index name not set. Use "indexOpen" method.');
        }

        // Add the unique ID
        $document->setField(
            'objectID',
            $this->getUniqueDocumentId($document)
        );

        $responseData = $this->client
            ->saveObject(
                $this->indexName,
                $document->getFields()
            );

        if (is_array($responseData)) {
            $responseData = new SaveObjectResponse($responseData);
        }

        return $responseData
            ->valid();
    }

    /**
     * Updates an existing document in the current Algolia index.
     *
     * In Algolia, adding and updating documents use the same operation,
     * so this method simply delegates to documentAdd(). If a document with
     * the same objectID already exists, it will be replaced; otherwise,
     * a new document will be created.
     *
     * @param Document $document The document to update
     *
     * @return bool True if the document was successfully updated, false otherwise
     *
     * @throws RuntimeException If no index is currently open
     */
    #[Override]
    public function documentUpdate(Document $document): bool
    {
        return $this->documentAdd($document);
    }

    /**
     * Deletes a document from the current Algolia index.
     *
     * This method removes a document with the specified ID from the currently
     * open index. The operation is permanent and cannot be undone. The index
     * must be opened with indexOpen() before calling this method.
     *
     * @param string $documentId The unique ID of the document to delete
     *
     * @return bool True if the document was successfully deleted, false otherwise
     *
     * @throws RuntimeException If no index is currently open
     */
    #[Override]
    public function documentDelete(string $documentId): bool
    {
        if ($this->indexName === null) {
            throw new RuntimeException('Index name not set. Use "indexOpen" method.');
        }

        $responseData = $this->client
            ->deleteObject(
                $this->indexName,
                $documentId
            );

        if (is_array($responseData)) {
            $responseData = new DeletedAtResponse($responseData);
        }

        return $responseData
            ->valid();
    }
}
