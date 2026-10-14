<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteRefusedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;

use function array_diff;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;

/**
 * Event listener for handling record deletion operations in the search indexing system.
 *
 * This listener responds to DataHandlerRecordDeleteEvent and DataHandlerRecordDeletedEvent
 * events that are dispatched when records are deleted in the TYPO3 backend or through the
 * DataHandler API. It ensures that deleted records are removed from both the indexing queue
 * and the search engine index to maintain consistency between the TYPO3 database and the
 * search index.
 *
 * DataHandler may still refuse a delete command after the first event, and the record and
 * the content elements of a page are gone after the second one. So the listener collects
 * the context of the record on the first event and only removes the record on the second.
 *
 * The listener performs the following tasks:
 * - Determines the root page ID for the deleted record to establish the correct indexing context
 * - Removes the record from both the indexing queue and the search engine index
 * - For content elements, also processes the page that contained the element
 *   to ensure that page's index entry is updated to reflect the removal of the content
 * - For pages, also removes the subpages, the content elements of the whole tree, the
 *   translations and the records of other indexed tables that are deleted with it, from both
 *   the queue and the index, so that orphaned content doesn't remain in the search index
 *
 * This listener is essential for maintaining the integrity of the search index
 * when content is deleted from the TYPO3 system.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordDeleteEventListener
{
    /**
     * The context of the records that are about to be deleted, by table and record UID.
     *
     * The context is collected while the record still exists and is used when the
     * record was deleted.
     *
     * @var array<string, array<int, CollectedDeleteContext>>
     */
    private array $pending = [];

    /**
     * Constructor method for initializing the class dependencies.
     *
     * @param RecordHandler    $recordHandler    Instance of RecordHandler to manage records.
     * @param RecordRepository $recordRepository Instance of RecordRepository for data storage operations related to records.
     * @param PageRepository   $pageRepository   Instance of PageRepository for handling pages.
     *
     * @return void
     */
    public function __construct(
        private readonly RecordHandler $recordHandler,
        private readonly RecordRepository $recordRepository,
        private readonly PageRepository $pageRepository,
    ) {
    }

    /**
     * Collects the context of a record that is about to be deleted.
     *
     * This method is called when a DataHandlerRecordDeleteEvent is dispatched,
     * which happens before DataHandler checks the permissions of the user. It
     * changes nothing in the queue or the search index, but remembers what is
     * needed once the record was really deleted:
     *
     * 1. The root page ID of the record to establish the correct indexing context
     * 2. For content elements, the page that contains the element
     * 3. For pages, the subpages, the content elements of the whole tree, the
     *    translations and the records of other indexed tables, which are deleted
     *    together with the page, and the indexing services that serve them
     *
     * @param DataHandlerRecordDeleteEvent $event The record delete event containing information about the record
     *
     * @return void
     */
    public function __invoke(DataHandlerRecordDeleteEvent $event): void
    {
        $table     = $event->getTable();
        $recordUid = $event->getRecordUid();

        $pageRecord = $this->pageRepository
            ->getPageRecord($table, $recordUid);

        // A record that is gone already has no document to collect the context for
        if ($pageRecord === []) {
            return;
        }

        // Determine the root page ID for the event record
        $rootPageId = $this->recordHandler
            ->getRecordRootPageId(
                $pageRecord,
                $table,
                $recordUid,
            );

        $pageId = ($table === ContentIndexer::TABLE)
            ? (int) $pageRecord['pid']
            : false;

        $contentElementUids = [];
        $subPageIds         = [];
        $additionalRecords  = [];
        $siteRootIds        = [];

        if ($table !== 'pages') {
            // DataHandler deletes the translations of a record and the children of its inline
            // relations, with their translations, without a command of their own
            $additionalRecords = $this->recordRepository
                ->findDependentUids(
                    $table,
                    $recordUid,
                    [$table, ContentIndexer::TABLE, ...$this->getAdditionalTables()],
                );
        } else {
            $languageUid    = (int) ($pageRecord['sys_language_uid'] ?? 0);
            $defaultPageUid = (int) ($pageRecord['l10n_parent'] ?? 0);

            if (
                ($languageUid > 0)
                && ($defaultPageUid > 0)
            ) {
                // The translation of a page takes the records of its language along, which
                // are stored on the page of the default language
                $contentElementUids = $this->recordRepository
                    ->findUidsByPidAndLanguage(
                        ContentIndexer::TABLE,
                        $defaultPageUid,
                        $languageUid,
                    );

                $additionalRecords = $this->findAdditionalRecordsOfLanguage($defaultPageUid, $languageUid);
            } else {
                // DataHandler deletes the subpages and everything stored on them together
                // with the page without a command of their own
                $subPageIds = $this->pageRepository->getDescendantPageIds($recordUid);
                $treeIds    = [$recordUid, ...$subPageIds];

                $contentElementUids = $this->recordRepository
                    ->findUidsByPids(ContentIndexer::TABLE, $treeIds);

                $additionalRecords = $this->findAdditionalRecordsOfTree($treeIds);
                $siteRootIds       = $this->recordRepository
                    ->findUidsWhere(
                        'pages',
                        'uid',
                        $subPageIds,
                        ['is_siteroot' => 1],
                    );
            }
        }

        // The indexing services may be stored in the deleted tree and are deleted with it. A site
        // root below the deleted page was indexed by the services of its own site.
        $targets = [];

        if ($table === 'pages') {
            $rootPageIds = array_values(array_unique([$rootPageId, ...$siteRootIds]));

            foreach (['pages', ContentIndexer::TABLE, ...array_keys($additionalRecords)] as $targetTable) {
                $targets[$targetTable] ??= $this->resolveTargets($rootPageIds, $targetTable);
            }
        }

        $this->pending[$table][$recordUid] = new CollectedDeleteContext(
            $rootPageId,
            $pageId,
            $contentElementUids,
            $subPageIds,
            $additionalRecords,
            $targets,
        );
    }

    /**
     * Updates the search index for a record that DataHandler deleted.
     *
     * This method is called when a DataHandlerRecordDeletedEvent is dispatched.
     * It uses the context that was collected before the deletion and:
     *
     * 1. Removes the record from both the indexing queue and the search engine index
     * 2. For content elements, also processes the page that contained the element
     *    to ensure that page's index entry is updated to reflect the removal of the content
     * 3. For pages, also removes the subpages, all content elements of the tree, the
     *    translations of the pages and the records of other indexed tables from both the
     *    queue and the index, with the indexing services that were resolved before the
     *    deletion
     * 4. Removes the translations of the record, which are deleted together with it
     *
     * A record whose context was not collected is left alone.
     *
     * @param DataHandlerRecordDeletedEvent $event The event announcing the deleted record
     *
     * @return void
     */
    public function onDeleted(DataHandlerRecordDeletedEvent $event): void
    {
        $table     = $event->getTable();
        $recordUid = $event->getRecordUid();

        $context = $this->pending[$table][$recordUid] ?? null;

        if ($context === null) {
            return;
        }

        unset($this->pending[$table][$recordUid]);

        // The page tree is removed with the indexing services that were resolved
        // before the deletion, because they may be deleted with the tree
        if ($table === 'pages') {
            // The page itself is gone, it is the reason for this call. A subpage is checked like a
            // translation, because the delete of a tree is all-or-nothing only for a command that
            // goes through the permission check, and the publish of a workspace delete does not
            $removals = [
                'pages' => array_merge(
                    [$recordUid],
                    $this->findDeletedUids('pages', $context->subPageIds),
                    $this->findDeletedUids('pages', $context->additionalRecords['pages'] ?? [])
                ),
                ContentIndexer::TABLE => $context->contentElementUids,
            ];

            foreach ($context->additionalRecords as $additionalTable => $additionalUids) {
                $removals[$additionalTable] ??= $additionalUids;
            }

            foreach ($removals as $removalTable => $removalUids) {
                $this->recordHandler
                    ->removeRecordsFromTargets(
                        $context->targets[$removalTable] ?? [],
                        $removalTable,
                        // The pages were checked for existence above. Every other table is checked
                        // here, so an element that DataHandler leaves behind keeps its document
                        ($removalTable === 'pages')
                            ? $removalUids
                            : $this->findDeletedUids($removalTable, $removalUids)
                    );
            }

            return;
        }

        $this->recordHandler
            ->removeRecords(
                $context->rootPageId,
                $table,
                [$recordUid],
            );

        if ($context->pageId !== false) {
            $this->recordHandler
                ->processPageOfContentElement($context->rootPageId, $context->pageId);
        }

        // The translations of the record are deleted together with it
        foreach ($context->additionalRecords as $additionalTable => $additionalUids) {
            $this->recordHandler
                ->removeRecords(
                    $context->rootPageId,
                    $additionalTable,
                    $this->findDeletedUids($additionalTable, $additionalUids)
                );
        }
    }

    /**
     * Discards the context of a record whose delete command ended without a deletion.
     *
     * The command was refused or changed nothing, so no deleted event follows and
     * the collected context, which can hold a whole page tree, is not needed.
     *
     * @param DataHandlerRecordDeleteRefusedEvent $event The event announcing the record that was not deleted
     *
     * @return void
     */
    public function onRefused(DataHandlerRecordDeleteRefusedEvent $event): void
    {
        unset($this->pending[$event->getTable()][$event->getRecordUid()]);
    }

    /**
     * Returns those of the collected records that are really deleted now.
     *
     * DataHandler checks the permissions of a translation on its own, so a
     * translation can stay while the record it belongs to is deleted.
     *
     * @param string $tableName  The database table name of the records
     * @param int[]  $recordUids The UIDs of the collected records
     *
     * @return int[] The UIDs of the records that do not exist any more
     */
    private function findDeletedUids(
        string $tableName,
        array $recordUids,
    ): array {
        return array_values(
            array_diff(
                $recordUids,
                $this->recordRepository->findExistingUids($tableName, $recordUids)
            )
        );
    }

    /**
     * Resolves the indexing services of several site roots for one table, each service once.
     *
     * @param int[]  $rootPageIds The UIDs of the root pages
     * @param string $tableName   The database table name of the records
     *
     * @return array<int, array{IndexingService, IndexerInterface}> The indexing services with their indexers
     */
    private function resolveTargets(
        array $rootPageIds,
        string $tableName,
    ): array {
        $targets = [];

        foreach ($rootPageIds as $rootPageId) {
            foreach ($this->recordHandler->resolveIndexerTargets($rootPageId, $tableName) as $target) {
                $targets[$target[0]->getUid() ?? 0] ??= $target;
            }
        }

        return array_values($targets);
    }

    /**
     * Returns the tables of the registered indexers that are not pages, content
     * elements or file metadata. The records of these tables are stored on pages.
     *
     * @return string[] The names of the tables
     */
    private function getAdditionalTables(): array
    {
        $tables = [];

        foreach (IndexerRegistry::getRegisteredIndexers() as $indexerConfiguration) {
            $tableName = $indexerConfiguration['tableName'];

            if (in_array($tableName, ['pages', ContentIndexer::TABLE, 'sys_file_metadata'], true)) {
                continue;
            }

            $tables[$tableName] = $tableName;
        }

        return array_values($tables);
    }

    /**
     * Finds the records that DataHandler deletes together with a page tree and that
     * are not content elements, grouped by table.
     *
     * These are the translations of the pages, which are pages on the parent page,
     * and the records of every other indexed table that is stored on one of the pages.
     *
     * @param int[] $pageIds The UIDs of the deleted page and its subpages
     *
     * @return array<string, int[]> The UIDs of the records by table
     */
    private function findAdditionalRecordsOfTree(array $pageIds): array
    {
        $records = [
            'pages' => $this->recordRepository->findTranslationUids('pages', $pageIds),
        ];

        foreach ($this->getAdditionalTables() as $tableName) {
            $records[$tableName] = $this->recordRepository->findUidsByPids($tableName, $pageIds);
        }

        return $records;
    }

    /**
     * Finds the records of one language that are stored on a page and that DataHandler
     * deletes together with the translation of the page, grouped by table.
     *
     * @param int $pageId      The UID of the page of the default language
     * @param int $languageUid The UID of the language of the deleted translation
     *
     * @return array<string, int[]> The UIDs of the records by table
     */
    private function findAdditionalRecordsOfLanguage(
        int $pageId,
        int $languageUid,
    ): array {
        $records = [];

        foreach ($this->getAdditionalTables() as $tableName) {
            $records[$tableName] = $this->recordRepository
                ->findUidsByPidAndLanguage(
                    $tableName,
                    $pageId,
                    $languageUid,
                );
        }

        return $records;
    }
}
