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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * The record update event.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordUpdateEventListener
{
    /**
     * @var IndexerFactory
     */
    private readonly IndexerFactory $indexerFactory;

    /**
     * @var IndexingServiceRepository
     */
    private readonly IndexingServiceRepository $indexingServiceRepository;

    /**
     * Constructor.
     *
     * @param IndexerFactory            $indexerFactory
     * @param IndexingServiceRepository $indexingServiceRepository
     */
    public function __construct(
        IndexerFactory $indexerFactory,
        IndexingServiceRepository $indexingServiceRepository,
    ) {
        $this->indexerFactory            = $indexerFactory;
        $this->indexingServiceRepository = $indexingServiceRepository;
    }

    /**
     * Invoke the event listener.
     *
     * @param DataHandlerRecordUpdateEvent $event
     */
    public function __invoke(DataHandlerRecordUpdateEvent $event): void
    {
        // The following considerations for the process precede:
        //
        // - Determine the indexer responsible for $event->getTable()
        //   - Currently, only one indexer is responsible/possible for each table
        // - Read the record using $event->getRecordUid()
        // - Determine the root page ID for the record
        // - Determine all configured indexing services created below the root page ID
        // - Handle the existing entry for the record in the queue table
        // - Perform indexing for all found indexing services

        // Determine the root page ID for the event record
        $rootPageId = $this->getRecordRootPageId(
            $event->getTable(),
            $event->getRecordUid()
        );

        // Determine all configured indexing services that are created below the root page ID
        $indexingServices = $this->indexingServiceRepository->findAll();

        /** @var IndexingService $indexingService */
        foreach ($indexingServices as $indexingService) {
            $indexerInstance = $this->indexerFactory
                ->createByType($indexingService->getType());

            if (!($indexerInstance instanceof IndexerInterface)) {
                continue;
            }

            // Indexer not responsible for this kind of table
            if ($indexerInstance->getTable() !== $event->getTable()) {
                continue;
            }

            if ($indexingService->getUid() === null) {
                continue;
            }

            // Determine the root page ID for the indexing service
            $indexingServiceRootPageId = $this->getRecordRootPageId(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                $indexingService->getUid()
            );

            // Ignore this indexing service as it is not responsible
            if ($rootPageId !== $indexingServiceRootPageId) {
                continue;
            }

            // Put the record into the queue
            $indexerInstance->enqueueOne(
                $indexingService,
                $event->getRecordUid()
            );
        }
    }

    /**
     * Returns the root page ID for the specified table and record UID.
     *
     * @param string      $tableName The table name used for the query
     * @param int<1, max> $recordUid The UID of the data record to be queried
     *
     * @return int
     */
    private function getRecordRootPageId(string $tableName, int $recordUid): int
    {
        $recordPageId = $recordUid;

        if ($tableName !== 'pages') {
            $recordPageId = $this->getRecordPageId($tableName, $recordUid);
        }

        return $this->getRootPageId($recordPageId);
    }

    /**
     * Returns the page ID of the record or 0 if no valid record was found.
     *
     * @param string $tableName The table name from which the record is retrieved
     * @param int    $recordUid The UID of the data record to be retrieved
     *
     * @return int
     */
    private function getRecordPageId(string $tableName, int $recordUid): int
    {
        $record = BackendUtility::getRecord($tableName, $recordUid, 'pid');

        if ($record === null) {
            return 0;
        }

        return $record['pid'] ? ((int) $record['pid']) : 0;
    }

    /**
     * Returns the root page ID for a given page ID. Returns 0 if the root page ID could not be determined.
     *
     * @param int $pageId The page ID to be used to determine the root page ID
     *
     * @return int
     */
    private function getRootPageId(int $pageId): int
    {
        $rootLineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $pageId);
        $rootLines       = $rootLineUtility->get();

        foreach ($rootLines as $rootLine) {
            if (isset($rootLine['is_siteroot']) && ($rootLine['is_siteroot'] === 1)) {
                return $rootLine['uid'];
            }
        }

        return 0;
    }
}
