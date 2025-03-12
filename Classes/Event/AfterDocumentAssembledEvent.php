<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Event;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;

/**
 * This event is triggered after the index document has been assembled and filled.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final class AfterDocumentAssembledEvent
{
    /**
     * @var Document
     */
    private Document $document;

    /**
     * @var IndexerInterface
     */
    private IndexerInterface $indexer;

    /**
     * @var array
     */
    private array $record;

    /**
     * Constructor.
     *
     * @param Document         $document
     * @param IndexerInterface $indexer
     * @param array            $record
     */
    public function __construct(
        Document $document,
        IndexerInterface $indexer,
        array $record,
    ) {
        $this->document = $document;
        $this->indexer  = $indexer;
        $this->record   = $record;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getIndexer(): IndexerInterface
    {
        return $this->indexer;
    }

    public function getRecord(): array
    {
        return $this->record;
    }
}
