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
 * Class AlgoliaSearchEngine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractSearchEngine implements SearchEngineInterface
{
    /**
     * @var EventDispatcherInterface
     */
    protected EventDispatcherInterface $eventDispatcher;

    /**
     * @var string|null
     */
    protected ?string $indexName = null;

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    #[Override]
    public function withIndexName(string $indexName): SearchEngineInterface
    {
        $clone            = clone $this;
        $clone->indexName = $indexName;

        return $clone;
    }

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
