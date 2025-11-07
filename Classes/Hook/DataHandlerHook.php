<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Hook;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

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
 * - After database operations (for record creation and updates)
 * - Before command processing (for record deletion)
 * - After command processing (for record moves and undeletions)
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
     * Event dispatcher for triggering search-related events.
     *
     * This property stores the event dispatcher service that is used to dispatch
     * events when record operations occur. These events (DataHandlerRecordUpdateEvent,
     * DataHandlerRecordDeleteEvent, DataHandlerRecordMoveEvent) notify the search
     * indexing system about changes to records that need to be reflected in the
     * search index.
     *
     * @var EventDispatcherInterface
     */
    private readonly EventDispatcherInterface $eventDispatcher;

    /**
     * Repository for page-related operations.
     *
     * This property stores the PageRepository service that provides methods for
     * retrieving page information and navigating page hierarchies. It is used to
     * find subpages of a modified page, which is necessary for updating the entire
     * page tree in the search index when a page is modified.
     *
     * @var PageRepository
     */
    private readonly PageRepository $pageRepository;

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
     * Constructor for initializing the service with its dependencies.
     *
     * This method sets up the required dependencies for the service,
     * such as the event dispatcher and page repository. These dependencies
     * enable the service to handle events and manage page-related data effectively.
     *
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher to dispatch events to listeners
     * @param PageRepository           $pageRepository  The repository for fetching and managing page records
     *
     * @return void
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        PageRepository $pageRepository,
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->pageRepository  = $pageRepository;
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
                new DataHandlerRecordUpdateEvent($table, $recordUid, $fields)
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
     * - Perform any necessary database queries while the record still exists
     * - Remove the record from the search index before it's removed from the database
     *
     * This ensures that deleted records are promptly removed from search results,
     * maintaining consistency between the TYPO3 database and the search index.
     *
     * @param string              $command      The DataHandler command (e.g., 'delete')
     * @param string              $table        The table currently processing data for
     * @param int<1, max>         $recordUid    The record uid currently processing data for
     * @param string|array<mixed> $commandValue The commands value, typically an array with more detailed command information
     * @param DataHandler         $dataHandler  The DataHandler parent object
     */
    public function processCmdmap_preProcess(
        string $command,
        string $table,
        int $recordUid,
        array|string $commandValue,
        DataHandler $dataHandler,
    ): void {
        if ($this->getBackendUser()->workspace !== 0) {
            return;
        }

        if ($command === 'delete') {
            $this->eventDispatcher
                ->dispatch(
                    new DataHandlerRecordDeleteEvent($table, $recordUid)
                );
        }
    }

    /**
     * Hooks into DataHandler after command processing to monitor record movements and undeletions.
     *
     * This method is called by TYPO3's DataHandler after a command has been executed.
     * It handles two specific command types:
     *
     * 1. Move commands:
     *    - Creates a DataHandlerRecordMoveEvent with information about the moved record
     *    - Sets the previous PID (source location) using data collected in the moveRecord() hook
     *    - Dispatches the event to notify listeners about the record movement
     *
     * 2. Undelete commands:
     *    - Creates a DataHandlerRecordUpdateEvent for the undeleted record
     *    - Dispatches the event to notify listeners that the record is available again
     *
     * This ensures that moved or undeleted records are properly handled in the search index,
     * maintaining consistency between the TYPO3 database and search results.
     *
     * @param string              $command      The DataHandler command (e.g., 'move', 'undelete')
     * @param string              $table        The table currently processing data for
     * @param int<1, max>         $recordUid    The record uid currently processing data for
     * @param string|array<mixed> $commandValue The commands value, typically an array with more detailed command information
     * @param DataHandler         $dataHandler  The DataHandler parent object
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        int $recordUid,
        array|string $commandValue,
        DataHandler $dataHandler,
    ): void {
        // TODO Handle copying of records

        // Skip draft records (workspace versions)
        if ($this->isRecordDraft($table, $recordUid)) {
            return;
        }

        if (
            ($command === 'move')
            && ($this->getBackendUser()->workspace === 0)
        ) {
            $event = new DataHandlerRecordMoveEvent(
                $table,
                $recordUid,
                (int) $commandValue
            );

            $event->setPreviousPid(
                $this->recordMovements[$table][(int) $commandValue] ?? null
            );

            $this->eventDispatcher->dispatch($event);
        }

        if ($command === 'undelete') {
            $this->eventDispatcher
                ->dispatch(
                    new DataHandlerRecordUpdateEvent($table, $recordUid)
                );
        }
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
        if (($table === 'pages') || ($table === 'tt_content')) {
            // Track the movement of record <TARGET PID> = <SOURCE PID>
            $this->recordMovements[$table][$destPid] = (int) $moveRec['pid'];
        }
    }

    /**
     * Retrieves the currently authenticated backend user.
     *
     * This method provides access to the global backend user authentication object.
     * The object contains user-specific data and permissions, enabling interaction
     * with the current backend user session.
     *
     * @return BackendUserAuthentication The backend user authentication object representing the current user.
     */
    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
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
            ->getPageRecord($tableName, $recordUid, 't3ver_state, t3ver_oid');

        if (
            isset($record['t3ver_state'])
            && ($record['t3ver_state'] !== VersionState::DEFAULT_STATE)
        ) {
            return true;
        }

        return isset($record['t3ver_oid']) && ($record['t3ver_oid'] !== 0);
    }
}
