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
 * The record delete event listener. This event listener is called when an existing record is deleted.
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
        if ($event->getTable() === 'tt_content') {
            // ???
        }

        if ($event->getTable() === 'pages') {
            // TODO Remove all indexed content elements from index
        }

        $this->deleteRecord($event->getTable(), $event->getRecordUid());
    }

    /**
     * @param string $tableName
     * @param int    $recordUid
     *
     * @return void
     */
    private function deleteRecord(string $tableName, int $recordUid): void
    {
        // Remove a possible entry of the element from the queue element table
        $this->queueItemRepository
            ->deleteByTableAndRecord(
                $tableName,
                $recordUid
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
                        $tableName,
                        $recordUid
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
