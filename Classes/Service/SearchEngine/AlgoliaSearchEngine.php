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
use Algolia\AlgoliaSearch\Model\Search\OperationIndexParams;
use Algolia\AlgoliaSearch\Model\Search\OperationType;
use Algolia\AlgoliaSearch\Model\Search\SaveObjectResponse;
use Algolia\AlgoliaSearch\Model\Search\UpdatedAtResponse;
use Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

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
    private string $appId;

    /**
     * @var string
     */
    private string $apiKey;

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

    public function indexOpen(string $indexName): void
    {
        $this->indexName = $indexName;
    }

    public function indexClose(): void
    {
        $this->indexName = null;
    }

    public function indexExists(string $indexName): bool
    {
        try {
            return $this->client
                ->indexExists($indexName);
        } catch (Throwable) {
            return false;
        }
    }

    public function indexDelete(string $indexName): bool
    {
        $responseData = $this->client
            ->deleteIndex($indexName);

        return (new DeletedAtResponse($responseData))
            ->valid();
    }

    public function indexCommit(): bool
    {
        return true;
    }

    public function indexMove(string $indexName, string $destination): bool
    {
        $responseData = $this->client
            ->operationIndex(
                $indexName,
                (new OperationIndexParams())
                    ->setOperation((new OperationType())::MOVE)
                    ->setDestination($destination)
            );

        return (new UpdatedAtResponse($responseData))
            ->valid();
    }

    /**
     * @param string $extKey
     * @param string $contentType
     * @param string $uid
     *
     * @return string
     */
    private function getUniqueObjectId(string $extKey, string $contentType, string $uid): string
    {
        return $extKey . ':' . $contentType . '-' . $uid;
    }

    public function documentAdd(Document $document): bool
    {
        $record = $document->getFields();

        //        // Primary key data (fields are all scalar)
        //        $primaryKeyData = $document->getPrimaryKey();
        //
        //        foreach ($primaryKeyData as $key => $field) {
        // //            if ($field instanceof tx_mksearch_model_IndexerFieldBase) {
        // //                $record[$key] = tx_mksearch_util_Misc::utf8Encode($field->getValue());
        // //            }
        //        }
        //
        //        foreach ($document->getData() as $key => $field) {
        // //            if ($field instanceof tx_mksearch_model_IndexerFieldBase) {
        // //                $record[$key] = tx_mksearch_util_Misc::utf8Encode($field->getValue());
        // //            }
        //        }
        //
        //        // An objectID must be specified for each record.
        //        $record['objectID'] = $this->getUniqueObjectId(
        //            $primaryKeyData['extKey']->getValue(),
        //            $primaryKeyData['contentType']->getValue(),
        //            $primaryKeyData['uid']->getValue()
        //        );

        $responseData = $this->client
            ->saveObject(
                $this->indexName,
                $record
            );

        return (new SaveObjectResponse($responseData))
            ->valid();
    }

    public function documentUpdate(Document $document): bool
    {
        return $this->documentAdd($document);
    }

    public function documentDelete(string $objectId): bool
    {
        $responseData = $this->client
            ->deleteObject(
                $this->indexName,
                $objectId
            );

        return (new DeletedAtResponse($responseData))
            ->valid();
    }
}
