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
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\RateLimitException;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Override;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function is_array;

/**
 * Class AlgoliaSearchEngine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AlgoliaSearchEngine implements SearchEngineInterface
{
    /**
     * @var SearchClient
     */
    private SearchClient $client;

    /**
     * @var string
     */
    private readonly string $appId;

    /**
     * @var string
     */
    private readonly string $apiKey;

    /**
     * @var string|null
     */
    private ?string $indexName = null;

    /**
     * Constructor.
     *
     * @param ExtensionConfiguration $extensionConfiguration
     */
    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
    ) {
        try {
            $configuration = $extensionConfiguration->get(Constants::EXTENSION_NAME);
        } catch (Exception) {
            $configuration = [];
        }

        $this->appId  = $configuration['appId'] ?? '';
        $this->apiKey = $configuration['apiKey'] ?? '';

        $this->init();
    }

    /**
     * Initializes the service.
     */
    public function init(): bool
    {
        $this->client = SearchClient::create(
            $this->appId,
            $this->apiKey
        );

        return true;
    }

    #[Override]
    public function indexOpen(string $indexName): void
    {
        $this->indexName = $indexName;
    }

    #[Override]
    public function indexClose(): void
    {
        $this->indexName = null;
    }

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

    #[Override]
    public function indexCommit(): bool
    {
        return true;
    }

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
     * @param string $indexName
     *
     * @return bool
     *
     * @throws RateLimitException
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
     * Returns a unique document ID for the document to index.
     *
     * @param Document $document
     *
     * @return string
     */
    private function getDocumentId(Document $document): string
    {
        return $document->getIndexer()->getTable() . ':' . $document->getRecord()['uid'];
    }

    #[Override]
    public function documentAdd(Document $document): bool
    {
        if ($this->indexName === null) {
            throw new RuntimeException('Index name not set. Use "indexOpen" method.');
        }

        // Add the unique ID
        $document->setField(
            'objectID',
            $this->getDocumentId($document)
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

    #[Override]
    public function documentUpdate(Document $document): bool
    {
        return $this->documentAdd($document);
    }

    #[Override]
    public function documentDelete(string $objectId): bool
    {
        if ($this->indexName === null) {
            throw new RuntimeException('Index name not set. Use "indexOpen" method.');
        }

        $responseData = $this->client
            ->deleteObject(
                $this->indexName,
                $objectId
            );

        if (is_array($responseData)) {
            $responseData = new DeletedAtResponse($responseData);
        }

        return $responseData
            ->valid();
    }
}
