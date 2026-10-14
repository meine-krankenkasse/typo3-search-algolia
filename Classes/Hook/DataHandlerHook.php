<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Hook;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\CategoryRecordRequeuerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteRefusedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

use function array_keys;
use function in_array;
use function is_array;
use function is_int;

/**
 * Hook into TYPO3's DataHandler to monitor record operations for search indexing.
 *
 * This class implements hooks for TYPO3's DataHandler to monitor record operations
 * (create, update, delete, move) and dispatch appropriate events to the search
 * indexing system. It serves as a bridge between TYPO3's core record handling
 * and the search extension's event-based architecture.
 *
 * The hook methods are called by TYPO3 at different stages of record processing:
 * - Before field processing (to remember the records of an existing category)
 * - After database operations (for record creation and updates)
 * - After all datamap operations (to requeue new categories and drop remembered records)
 * - Before command processing (to announce a delete command, the publish of a workspace delete or
 *   move, and to remember what it is sent for)
 * - After command processing (for record moves, undeletions, deletions, published workspace
 *   deletes and moves, and category deletions)
 * - After all commands (to forget what the commands remembered)
 * - During record movement (to track source and target locations)
 *
 * Each hook method dispatches specific events that are then handled by event
 * listeners to update the search index accordingly, ensuring that the search
 * index stays in sync with the TYPO3 database.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class DataHandlerHook
{
    /**
     * Tracks record movements during DataHandler operations.
     *
     * This property stores information about record movements, mapping target PIDs
     * to source PIDs for specific tables. It's used to keep track of where records
     * were moved from, which is essential for properly updating the search index
     * when records change location.
     *
     * The array structure is:
     * [table_name => [target_pid => source_pid]]
     *
     * This information is collected in the moveRecord() hook method and used in
     * the processCmdmap_postProcess() method to provide complete movement information
     * to the DataHandlerRecordMoveEvent.
     *
     * @var array<string, array<int, int>>
     */
    private array $recordMovements = [];

    /**
     * Remembers the records for which DataHandler called the move hook.
     *
     * DataHandler calls the post-process hook of a command also when it
     * refused the command, but the move hook only after its permission checks.
     * Only for a record remembered here a move event is dispatched. A move that
     * DataHandler still declines later, for example into the subtree of the
     * record itself, is announced as well.
     *
     * The array structure is:
     * [table_name => [record_uid => true]]
     *
     * @var array<string, array<int, true>>
     */
    private array $movedRecords = [];

    /**
     * Remembers the deleted records that an undelete command was sent for.
     *
     * DataHandler also runs the post-process hook for an undelete command that
     * does nothing, for example for a record that is not deleted. Only a record
     * that was deleted before the command and is live afterwards was undeleted.
     *
     * The array structure is:
     * [table_name => [record_uid => true]]
     *
     * @var array<string, array<int, true>>
     */
    private array $deletedRecordsToUndelete = [];

    /**
     * Remembers the live records that a delete command was sent for.
     *
     * DataHandler runs the pre-process hook of a command before it checks the
     * permissions of the user, so a record may still be live after the command.
     * Only a record remembered here that is deleted afterwards is announced as
     * deleted.
     *
     * The array structure is:
     * [table_name => [record_uid => true]]
     *
     * @var array<string, array<int, true>>
     */
    private array $recordsToDelete = [];

    /**
     * Remembers the parent that a live record had before a workspace move was published.
     *
     * Publishing a workspace move gives the live record the parent of the move pointer
     * through a version command. DataHandler calls neither the move hook nor a move command
     * for it, so the parent before the publish is only known here.
     *
     * The array structure is:
     * [table_name => [record_uid => parent_uid_before_publish]]
     *
     * @var array<string, array<int, int>>
     */
    private array $publishedMoves = [];

    /**
     * Constructor for initializing the service with its dependencies.
     *
     * This method sets up the required dependencies for the service,
     * such as the event dispatcher and page repository. These dependencies
     * enable the service to handle events and manage page-related data effectively.
     *
     * @param EventDispatcherInterface        $eventDispatcher        The event dispatcher to dispatch events to listeners
     * @param PageRepository                  $pageRepository         The repository for fetching and managing page records
     * @param CategoryRecordRequeuerInterface $categoryRecordRequeuer The requeuer handling the records of changed categories
     *
     * @return void
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly PageRepository $pageRepository,
        private readonly CategoryRecordRequeuerInterface $categoryRecordRequeuer,
    ) {
    }

    /**
     * Hooks into DataHandler before a record's field values are processed, to
     * remember the records assigned to an existing category before the save.
     *
     * DataHandler writes the category's MM assignments (e.g. its items field)
     * before the after-database-operations hook runs, so the records whose
     * assignment the save removes are only known here.
     *
     * @param array<string, int|float|string|array<array-key, mixed>|null> $incomingFieldArray The submitted field values of the record
     * @param string                                                       $table              The table currently processing data for
     * @param int|string                                                   $id                 The record uid, or a 'NEW...' placeholder for a new record
     * @param DataHandler                                                  $dataHandler        The DataHandler parent object
     *
     * @return void
     */
    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler,
    ): void {
        if (
            ($table !== 'sys_category')
            || !MathUtility::canBeInterpretedAsInteger($id)
            || !$this->actsOnLiveRecords($dataHandler, $table)
        ) {
            return;
        }

        // DataHandler checks the access to the record after this hook. A save it refuses
        // is not worth the walk through the descendants and the assignments of the category
        if (!$dataHandler->checkRecordUpdateAccess($table, (int) $id)) {
            return;
        }

        $this->categoryRecordRequeuer->rememberAssignedRecords((int) $id);
    }

    /**
     * Hooks into DataHandler after all records of the datamap were processed,
     * to requeue the records of newly created categories and to drop the
     * records remembered for the saved existing categories.
     *
     * DataHandler writes the MM assignments of new records only after the
     * per-record hooks ran, so the records of a new category can only be found
     * at this point. The remembered records of a successful save were already
     * requeued. Those of a save DataHandler refused would otherwise stay behind.
     *
     * @param DataHandler $dataHandler The DataHandler parent object
     *
     * @return void
     */
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (!$this->actsOnLiveRecords($dataHandler, 'sys_category')) {
            return;
        }

        foreach ($dataHandler->substNEWwithIDs_table as $newId => $table) {
            if ($table === 'sys_category') {
                $this->categoryRecordRequeuer->requeue((int) $dataHandler->substNEWwithIDs[$newId]);
            }
        }

        foreach (array_keys($dataHandler->datamap['sys_category'] ?? []) as $id) {
            if (MathUtility::canBeInterpretedAsInteger($id)) {
                $this->categoryRecordRequeuer->forgetRememberedRecords((int) $id);
            }
        }
    }

    /**
     * Hooks into DataHandler after database operations to monitor record creations and updates.
     *
     * This method is called by TYPO3's DataHandler after a record has been created or updated
     * in the database. It dispatches a DataHandlerRecordUpdateEvent with information about
     * the affected record, which is then handled by event listeners to update the search index.
     *
     * The method handles both new records and updates to existing records:
     * - For new records, it resolves the 'NEW...' string to the actual record UID
     * - For updates, it uses the provided record UID directly
     * - In both cases, it dispatches an event with the table name, record UID, and changed fields
     *
     * This ensures that newly created or updated records are properly indexed in the search engine,
     * keeping the search index in sync with the TYPO3 database.
     *
     * @param string                    $status      The status of the current operation, 'new' or 'update'
     * @param string                    $table       The table currently processing data for
     * @param int<1, max>|string        $recordUid   The record uid currently processing data for, [integer] or [string] (like 'NEW...')
     * @param array<string, int|string> $fields      The changed field array of a record
     * @param DataHandler               $dataHandler The DataHandler parent object
     *
     * @return void
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $recordUid,
        array $fields,
        DataHandler $dataHandler,
    ): void {
        // Get new record UID for newly created record
        if (($status === 'new') && !MathUtility::canBeInterpretedAsInteger($recordUid)) {
            if (isset($dataHandler->substNEWwithIDs[$recordUid])) {
                /** @var int<1, max> $recordUid */
                $recordUid = $dataHandler->substNEWwithIDs[$recordUid];
            } else {
                return;
            }
        }

        if (!is_int($recordUid)) {
            return;
        }

        // Skip draft records (workspace versions)
        if ($this->isRecordDraft($table, $recordUid)) {
            return;
        }

        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordUpdateEvent($table, $recordUid, $fields, isNew: $status === 'new')
            );
    }

    /**
     * Hooks into DataHandler before command processing to monitor record deletions.
     *
     * This method is called by TYPO3's DataHandler before a command (like delete)
     * is executed. For delete commands, it dispatches a DataHandlerRecordDeleteEvent
     * with information about the record being deleted, which is then handled by
     * event listeners to update the search index.
     *
     * The hook is called before the record is actually deleted from TYPO3, which
     * is crucial because it allows the search indexing system to:
     * - Access the complete record data that's about to be deleted
     * - Perform any necessary database queries while the record still exists,
     *   such as the root page, the page of a content element and the content
     *   elements of a page
     *
     * The command may still be refused after this hook, so the record is only
     * removed from the search index once processCmdmap_postProcess() announced
     * that it is deleted.
     *
     * The version command with the action swap or publish publishes a workspace
     * version. A delete placeholder deletes the live record and a move pointer moves
     * it, both without a delete or move command, so the delete is collected here
     * and the former parent of a move is remembered.
     *
     * @param string                                                           $command      The DataHandler command (e.g., 'delete')
     * @param string                                                           $table        The table currently processing data for
     * @param int|string                                                       $recordUid    The record uid currently processing data for, a key that reads as no record is ignored
     * @param string|array<array-key, int|string|array<array-key, mixed>|null> $commandValue The commands value, typically an array with more detailed command information
     * @param DataHandler                                                      $dataHandler  The DataHandler parent object
     *
     * @return void
     */
    public function processCmdmap_preProcess(
        string $command,
        string $table,
        int|string $recordUid,
        array|string $commandValue,
        DataHandler $dataHandler,
    ): void {
        $recordUid = $this->readRecordUid($command, $recordUid);

        // A key that reads as no record is no command for a record
        if ($recordUid < 1) {
            return;
        }

        if (
            ($command === 'undelete')
            && !$this->pageRepository->hasPageRecord($table, $recordUid)
            && $this->pageRepository->hasPageRecord($table, $recordUid, false)
        ) {
            $this->deletedRecordsToUndelete[$table][$recordUid] = true;
        }

        // Publishing a workspace delete or move changes the live record through the version
        // command, whatever workspace the user works in
        if (
            ($command === 'version')
            && $this->rememberPublishedVersion($table, $recordUid, $commandValue, $dataHandler)
        ) {
            $this->eventDispatcher
                ->dispatch(
                    new DataHandlerRecordDeleteEvent($table, $recordUid)
                );

            return;
        }

        if (!$this->actsOnLiveRecords($dataHandler, $table)) {
            return;
        }

        if ($command === 'delete') {
            if ($this->pageRepository->hasPageRecord($table, $recordUid)) {
                $this->recordsToDelete[$table][$recordUid] = true;
            }

            // DataHandler checks the permissions after this hook, so the command may still be
            // refused. A refusal that is certain is left out of the collection, because the
            // collection of a page tree is not cheap. A command that may still be refused is
            // collected, and the refusal is announced in processCmdmap_postProcess(), which
            // discards the context.
            if (
                ($table === 'pages')
                && $this->isPageDeleteRefusedForSure($dataHandler, $recordUid)
            ) {
                return;
            }

            $this->eventDispatcher
                ->dispatch(
                    new DataHandlerRecordDeleteEvent($table, $recordUid)
                );
        }
    }

    /**
     * Hooks into DataHandler after command processing to monitor record movements,
     * undeletions and category deletions.
     *
     * This method is called by TYPO3's DataHandler after a command has been executed.
     * It handles these command types:
     *
     * 1. Move commands:
     *    - Only for a record for which DataHandler called the move hook, see moveRecord()
     *    - Creates a DataHandlerRecordMoveEvent with information about the moved record,
     *      whose target is the parent that DataHandler stored for the record
     *    - Sets the previous PID (source location) using data collected in the moveRecord() hook
     *    - Dispatches the event to notify listeners about the record movement
     *
     * 2. Undelete commands:
     *    - Only for a record that was deleted before the command and is live afterwards
     *    - Creates a DataHandlerRecordUpdateEvent for the undeleted record
     *    - Dispatches the event to notify listeners that the record is available again
     *
     * 3. Delete commands:
     *    - Only for a record that was live before the command and is deleted afterwards
     *    - Creates a DataHandlerRecordDeletedEvent for the deleted record
     *    - Otherwise, because the command was refused or changed nothing, creates a
     *      DataHandlerRecordDeleteRefusedEvent so that the context that was collected
     *      before the permission check is discarded
     *
     * 4. Delete commands on system categories:
     *    - Requeues the records of the category, if it really was deleted
     *
     * 5. Version commands that publish a workspace delete or move:
     *    - Announce the delete like a delete command, from what the pre-process hook remembered
     *    - Announce the move with the parent the live record had before the publish
     *
     * This ensures that moved or undeleted records are properly handled in the search index,
     * maintaining consistency between the TYPO3 database and search results.
     *
     * @param string                                                           $command      The DataHandler command (e.g., 'move', 'undelete')
     * @param string                                                           $table        The table currently processing data for
     * @param int|string                                                       $recordUid    The record uid currently processing data for, a key that reads as no record is ignored
     * @param string|array<array-key, int|string|array<array-key, mixed>|null> $commandValue The commands value, typically an array with more detailed command information
     * @param DataHandler                                                      $dataHandler  The DataHandler parent object
     *
     * @return void
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        int|string $recordUid,
        array|string $commandValue,
        DataHandler $dataHandler,
    ): void {
        $recordUid = $this->readRecordUid($command, $recordUid);

        // A key that reads as no record is no command for a record
        if ($recordUid < 1) {
            return;
        }

        // DataHandler moves the live record when a move command names its workspace
        // version, and tells the move hook by the UID of the live record
        if ($command === 'move') {
            $recordUid = $this->resolveLiveRecordUid($table, $recordUid);
        }

        // TODO Handle copying of records

        // The outcome of a delete command that acts on live records is always announced, as
        // deleted or as refused, and so is the one of a published workspace delete, so that
        // nothing stays collected for it. A command that does not act on live records is not
        // announced at all
        if (
            (
                ($command === 'delete')
                && $this->actsOnLiveRecords($dataHandler, $table)
            )
            || (
                ($command === 'version')
                && isset($this->recordsToDelete[$table][$recordUid])
            )
        ) {
            $wasLive = isset($this->recordsToDelete[$table][$recordUid]);

            unset($this->recordsToDelete[$table][$recordUid]);

            if (
                $wasLive
                && !$this->pageRepository->hasPageRecord($table, $recordUid)
            ) {
                $this->eventDispatcher
                    ->dispatch(
                        new DataHandlerRecordDeletedEvent($table, $recordUid)
                    );
            } else {
                // The command was refused or changed nothing, so the context that
                // was collected for it is not needed any more
                $this->eventDispatcher
                    ->dispatch(
                        new DataHandlerRecordDeleteRefusedEvent($table, $recordUid)
                    );
            }
        }

        // A published workspace move announces the parent the live record has now
        if (
            ($command === 'version')
            && isset($this->publishedMoves[$table][$recordUid])
        ) {
            $previousPid = $this->publishedMoves[$table][$recordUid];

            unset($this->publishedMoves[$table][$recordUid]);

            $this->announceMove($table, $recordUid, $previousPid);
        }

        // Skip draft records (workspace versions)
        if ($this->isRecordDraft($table, $recordUid)) {
            return;
        }

        if (
            ($command === 'move')
            && $this->actsOnLiveRecords($dataHandler, $table)
            && isset($this->movedRecords[$table][$recordUid])
        ) {
            unset($this->movedRecords[$table][$recordUid]);

            // The command value can be negative, which means after the page with that
            // UID, so the parent that DataHandler stored is the one that counts
            $this->announceMove(
                $table,
                $recordUid,
                $this->recordMovements[$table][(int) $commandValue] ?? null
            );
        }

        if (
            ($command === 'undelete')
            && isset($this->deletedRecordsToUndelete[$table][$recordUid])
            && $this->pageRepository->hasPageRecord($table, $recordUid)
        ) {
            unset($this->deletedRecordsToUndelete[$table][$recordUid]);

            $this->eventDispatcher
                ->dispatch(
                    new DataHandlerRecordUpdateEvent($table, $recordUid)
                );
        }

        if (
            ($command === 'delete')
            && ($table === 'sys_category')
            && $this->actsOnLiveRecords($dataHandler, $table)
            && !$this->pageRepository->hasPageRecord($table, $recordUid)
        ) {
            // Requeue only once the category is really deleted, so a refused delete requeues
            // nothing and the queue worker cannot index the records with the category still live
            $this->categoryRecordRequeuer->requeue($recordUid);
        }
    }

    /**
     * Hooks into DataHandler after all commands were processed.
     *
     * DataHandler calls the move hook for the translations and inline children of a
     * moved record as well, and those get no post-process call. The service is shared
     * by every DataHandler of the request, so what a command left behind is forgotten
     * once the outermost DataHandler has finished. A nested DataHandler, which a hook
     * of another extension can start while a command of the outer one is in progress,
     * must leave the state of that outer command alone.
     *
     * @param DataHandler $dataHandler The DataHandler parent object
     *
     * @return void
     */
    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        if (!$dataHandler->isOuterMostInstance()) {
            return;
        }

        $this->recordsToDelete          = [];
        $this->publishedMoves           = [];
        $this->movedRecords             = [];
        $this->recordMovements          = [];
        $this->deletedRecordsToUndelete = [];
    }

    /**
     * Hooks into DataHandler during record movement to track source and target locations.
     *
     * This method is called by TYPO3's DataHandler during the process of moving a record.
     * It's specifically designed to track the source and target locations of pages and
     * content elements, which is essential for properly updating the search index when
     * records change location.
     *
     * The method stores the source PID (original location) and target PID (new location)
     * in the $recordMovements property, which is later used by the processCmdmap_postProcess()
     * method to provide complete movement information to the DataHandlerRecordMoveEvent.
     *
     * This tracking is particularly important for:
     * - Updating page records in the search index with their new location
     * - Updating content elements that have moved to a different page
     * - Ensuring that parent-child relationships are maintained in the search index
     *
     * @param string       $table          The table currently processing data for (e.g., 'pages', 'tt_content')
     * @param int          $uid            The record uid currently processing data for
     * @param int          $destPid        The target parent ID (destination)
     * @param array<mixed> $propArr        The record properties
     * @param array<mixed> $moveRec        The moved record data including the original pid
     * @param int          $resolvedPid    The resolved parent ID
     * @param bool         $recordWasMoved Set to TRUE if the hook already moved the record
     * @param DataHandler  $dataHandler    The DataHandler parent object
     *
     * @return void
     */
    public function moveRecord(
        string $table,
        int $uid,
        int $destPid,
        array $propArr,
        array $moveRec,
        int $resolvedPid,
        bool &$recordWasMoved,
        DataHandler $dataHandler,
    ): void {
        $this->movedRecords[$table][$uid] = true;

        if (($table === 'pages') || ($table === 'tt_content')) {
            // Track the movement of record <TARGET PID> = <SOURCE PID>
            $this->recordMovements[$table][$destPid] = (int) $moveRec['pid'];
        }
    }

    /**
     * Tells whether DataHandler refuses the delete of a live page for certain, because
     * the user has no delete permission for the page itself.
     *
     * A translation of a page is checked against its default language page by
     * DataHandler, so it is never reported as refused here, and neither is a page
     * that is not there.
     *
     * @param DataHandler $dataHandler The DataHandler that carries out the command
     * @param int         $pageUid     The UID of the page
     *
     * @return bool TRUE if DataHandler refuses the delete for sure
     */
    private function isPageDeleteRefusedForSure(
        DataHandler $dataHandler,
        int $pageUid,
    ): bool {
        $pageRecord = $this->pageRepository
            ->getPageRecord(
                'pages',
                $pageUid,
                'sys_language_uid',
            );

        if (
            ($pageRecord === [])
            || (((int) $pageRecord['sys_language_uid']) !== 0)
        ) {
            return false;
        }

        return !$dataHandler->doesRecordExist('pages', $pageUid, Permission::PAGE_DELETE);
    }

    /**
     * Announces the move of a record with the parent that DataHandler stored for it.
     *
     * A record that is not there any more is not announced. A move that left the parent
     * as it was is announced as well, and the listener of the event tells that the record
     * did not change its place.
     *
     * @param string      $table       The table of the record
     * @param int<1, max> $recordUid   The UID of the record
     * @param int|null    $previousPid The parent before the move, NULL if it is not known
     *
     * @return void
     */
    private function announceMove(
        string $table,
        int $recordUid,
        ?int $previousPid,
    ): void {
        $storedRecord = $this->pageRepository
            ->getPageRecord(
                $table,
                $recordUid,
                'pid',
            );

        if ($storedRecord === []) {
            return;
        }

        $event = new DataHandlerRecordMoveEvent(
            $table,
            $recordUid,
            (int) $storedRecord['pid']
        );

        $event->setPreviousPid($previousPid);

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Reads the key of a command as the UID of a record, the way DataHandler reads it.
     *
     * DataHandler casts the key of a delete, move or undelete command, so a key such as
     * 3abc is a command for record 3 and the hook follows that reading, or the documents
     * of a record that was really deleted would stay. The key of a version command has to
     * be a number, anything else is no command that DataHandler carries out.
     *
     * @param string     $command The DataHandler command
     * @param int|string $key     The key of the command, the UID of the record
     *
     * @return int The UID of the record, 0 for a key that reads as none
     */
    private function readRecordUid(
        string $command,
        int|string $key,
    ): int {
        if (
            ($command === 'version')
            && !MathUtility::canBeInterpretedAsInteger($key)
        ) {
            return 0;
        }

        return (int) $key;
    }

    /**
     * Resolves the UID of a workspace version to the UID of its live record, like
     * DataHandler does for the record a move command names.
     *
     * @param string      $table     The table of the record
     * @param int<1, max> $recordUid The UID of the record the command names
     *
     * @return int<1, max> The UID of the live record, the given UID for a record that is no version
     */
    private function resolveLiveRecordUid(
        string $table,
        int $recordUid,
    ): int {
        if (!BackendUtility::isTableWorkspaceEnabled($table)) {
            return $recordUid;
        }

        $record  = $this->pageRepository->getPageRecord($table, $recordUid, 't3ver_oid');
        $liveUid = (int) ($record['t3ver_oid'] ?? 0);

        return ($liveUid > 0) ? $liveUid : $recordUid;
    }

    /**
     * Remembers what the publish of a workspace version does to the live record.
     *
     * A version command with the action swap or publish gives the live record the content
     * of the workspace version. A delete placeholder deletes the live record with it, and a
     * move pointer gives it a new parent. Both change the live record without a delete or
     * move command, so what is needed afterwards has to be remembered before the swap.
     *
     * @param string                                                           $table        The table of the live record
     * @param int                                                              $recordUid    The UID of the live record
     * @param string|array<array-key, int|string|array<array-key, mixed>|null> $commandValue The value of the version command
     * @param DataHandler                                                      $dataHandler  The DataHandler that runs the command
     *
     * @return bool TRUE if the publish deletes the live record and the user may edit it, so that the
     *              context of the delete has to be collected
     */
    private function rememberPublishedVersion(
        string $table,
        int $recordUid,
        array|string $commandValue,
        DataHandler $dataHandler,
    ): bool {
        if (
            !is_array($commandValue)
            || !in_array($commandValue['action'] ?? '', ['swap', 'publish'], true)
            || !isset($commandValue['swapWith'])
            || !MathUtility::canBeInterpretedAsInteger($commandValue['swapWith'])
            || !BackendUtility::isTableWorkspaceEnabled($table)
        ) {
            return false;
        }

        $version = $this->pageRepository
            ->getPageRecord(
                $table,
                (int) $commandValue['swapWith'],
                'pid, t3ver_oid, t3ver_state',
            );

        $live = $this->pageRepository
            ->getPageRecord(
                $table,
                $recordUid,
                'pid',
            );

        if (
            ($version === [])
            || ($live === [])
            || (((int) $version['t3ver_oid']) !== $recordUid)
        ) {
            return false;
        }

        $state = (int) $version['t3ver_state'];

        if ($state === VersionState::DELETE_PLACEHOLDER) {
            $this->recordsToDelete[$table][$recordUid] = true;

            // A publish that is refused for sure collects nothing, and is still announced as
            // refused, because the collection of a tree is not cheap
            return (bool) $dataHandler->checkRecordUpdateAccess($table, $recordUid);
        }

        if ($state === VersionState::MOVE_POINTER) {
            $this->publishedMoves[$table][$recordUid] = (int) $live['pid'];
        }

        return false;
    }

    /**
     * Tells whether a command of the DataHandler changes the live record of a table.
     *
     * A user of a workspace works on versions of the records of a table that is
     * workspace-enabled, so the live record and its document stay as they are. The
     * workspace of the user decides whether the live record of a table can be changed
     * at all, the backend user answers that for the table. DataHandler checks the
     * permissions as well, a command that it refuses is announced as refused.
     *
     * @param DataHandler $dataHandler The DataHandler that runs the command
     * @param string      $table       The table of the record
     *
     * @return bool TRUE if the command acts on the live record
     */
    private function actsOnLiveRecords(
        DataHandler $dataHandler,
        string $table,
    ): bool {
        return $this->getBackendUser($dataHandler)->workspaceAllowsLiveEditingInTable($table);
    }

    /**
     * Retrieves the backend user DataHandler runs its commands for.
     *
     * A DataHandler can be started for another user than the global one, for
     * example by a scheduler task or a command, and judges workspace and
     * permissions by that user.
     *
     * @param DataHandler $dataHandler The DataHandler that runs the command
     *
     * @return BackendUserAuthentication The backend user of the DataHandler, or the global one without
     */
    private function getBackendUser(DataHandler $dataHandler): BackendUserAuthentication
    {
        return ($dataHandler->BE_USER instanceof BackendUserAuthentication)
            ? $dataHandler->BE_USER
            : $GLOBALS['BE_USER'];
    }

    /**
     * Determines whether a record is a draft version in a workspace.
     *
     * This method checks if a record from the specified table and UID is a draft
     * version by evaluating workspace-specific properties such as `t3ver_state` and `t3ver_oid`.
     * It ensures that the record belongs to a workspace-enabled table and checks
     * the corresponding version state.
     *
     * @param string $tableName The name of the database table to retrieve the record from.
     * @param int    $recordUid The unique identifier (UID) of the record to evaluate.
     *
     * @return bool Returns true if the record is a draft associated with a workspace; false otherwise.
     */
    private function isRecordDraft(string $tableName, int $recordUid): bool
    {
        if (
            !ExtensionManagementUtility::isLoaded('workspaces')
            || !BackendUtility::isTableWorkspaceEnabled($tableName)
        ) {
            return false;
        }

        $record = $this->pageRepository
            ->getPageRecord(
                $tableName,
                $recordUid,
                't3ver_state, t3ver_oid',
            );

        if (
            isset($record['t3ver_state'])
            && ($record['t3ver_state'] !== VersionState::DEFAULT_STATE)
        ) {
            return true;
        }

        return isset($record['t3ver_oid']) && ($record['t3ver_oid'] !== 0);
    }
}
