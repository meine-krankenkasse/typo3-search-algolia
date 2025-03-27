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
 * This event is triggered upon the requirement of creating a unique document ID to access a search engine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class CreateUniqueDocumentIdEvent
{
    /**
     * @var SearchEngineInterface
     */
    private readonly SearchEngineInterface $searchEngine;

    /**
     * @var string
     */
    private readonly string $tableName;

    /**
     * @var int
     */
    private readonly int $recordUid;

    /**
     * @var string
     */
    private string $documentId = '';

    /**
     * Constructor.
     *
     * @param SearchEngineInterface $searchEngine
     * @param string                $tableName
     * @param int                   $recordUid
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
     * @return SearchEngineInterface
     */
    public function getSearchEngine(): SearchEngineInterface
    {
        return $this->searchEngine;
    }

    /**
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * @return int
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * @return string
     */
    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    /**
     * @param string $documentId
     *
     * @return CreateUniqueDocumentIdEvent
     */
    public function setDocumentId(string $documentId): CreateUniqueDocumentIdEvent
    {
        $this->documentId = $documentId;

        return $this;
    }
}
