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
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class AfterDocumentAssembledEvent
{
    /**
     * The processed document.
     *
     * @var Document
     */
    private Document $document;

    /**
     * The indexer instance.
     *
     * @var IndexerInterface
     */
    private IndexerInterface $indexer;

    /**
     * The indexing service configuration.
     *
     * @var IndexingService
     */
    private IndexingService $indexingService;

    /**
     * The database record.
     *
     * @var array<string, mixed>
     */
    private array $record;

    /**
     * Constructor.
     *
     * @param Document             $document
     * @param IndexerInterface     $indexer
     * @param IndexingService      $indexingService
     * @param array<string, mixed> $record
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
     * @return Document
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * @return IndexerInterface
     */
    public function getIndexer(): IndexerInterface
    {
        return $this->indexer;
    }

    /**
     * @return IndexingService
     */
    public function getIndexingService(): IndexingService
    {
        return $this->indexingService;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecord(): array
    {
        return $this->record;
    }
}
