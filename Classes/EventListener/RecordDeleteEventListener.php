<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The record delete event listener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class RecordDeleteEventListener
{
    /**
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $eventDispatcher;

    /**
     * @var SearchEngineFactory
     */
    private SearchEngineFactory $searchEngineFactory;

    /**
     * @var IndexingServiceRepository
     */
    private IndexingServiceRepository $indexingServiceRepository;

    /**
     * @var QueueItemRepository
     */
    private QueueItemRepository $queueItemRepository;

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface  $eventDispatcher
     * @param SearchEngineFactory       $searchEngineFactory
     * @param IndexingServiceRepository $indexingServiceRepository
     * @param QueueItemRepository       $queueItemRepository
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        SearchEngineFactory $searchEngineFactory,
        IndexingServiceRepository $indexingServiceRepository,
        QueueItemRepository $queueItemRepository,
    ) {
        $this->eventDispatcher           = $eventDispatcher;
        $this->searchEngineFactory       = $searchEngineFactory;
        $this->indexingServiceRepository = $indexingServiceRepository;
        $this->queueItemRepository       = $queueItemRepository;
    }

    /**
     * Invoke the event listener.
     *
     * @param DataHandlerRecordDeleteEvent $event
     */
    public function __invoke(DataHandlerRecordDeleteEvent $event): void
    {
        if ($event->getTable() === 'pages') {
            // Remove the record from the queue item table
            $this->queueItemRepository
                ->deleteByTableAndRecord(
                    $event->getTable(),
                    $event->getRecordUid()
                );

            // Determine all configured indexing services that are created below the root page ID
            $indexingServices = $this->indexingServiceRepository->findAll();

            /** @var IndexingService $indexingService */
            foreach ($indexingServices as $indexingService) {
                // Get underlying search engine instance
                $searchEngineService = $this->searchEngineFactory
                    ->makeInstanceBySearchEngineModel($indexingService->getSearchEngine());

                if (!($searchEngineService instanceof SearchEngineInterface)) {
                    return;
                }

                /** @var CreateUniqueDocumentIdEvent $documentIdEvent */
                $documentIdEvent = $this->eventDispatcher
                    ->dispatch(
                        new CreateUniqueDocumentIdEvent(
                            $searchEngineService,
                            $event->getTable(),
                            $event->getRecordUid()
                        )
                    );

                // Remove record in search engine index
                $searchEngineService->indexOpen($indexingService->getSearchEngine()->getIndexName());
                $searchEngineService->documentDelete($documentIdEvent->getDocumentId());
                $searchEngineService->indexCommit();
                $searchEngineService->indexClose();
            }
        }
    }
}
