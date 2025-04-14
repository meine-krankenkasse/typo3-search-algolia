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
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;

use function is_int;

/**
 * Class DataHandlerHook.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class DataHandlerHook
{
    /**
     * @var EventDispatcherInterface
     */
    private readonly EventDispatcherInterface $eventDispatcher;

    /**
     * @var array<string, array<int, int>>
     */
    private array $recordMovements = [];

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
    ) {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Hooks into DataHandler and monitors all record creations and updates. If it determines that the new/updated
     * record belongs to a table configured for indexing, the record is added to the index queue.
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

        // TODO Handle workspaces?

        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordUpdateEvent($table, $recordUid, $fields)
            );
    }

    /**
     * Hooks into the DataHandler and monitors delete commands. The hook is called before the record is
     * deleted from TYPO3, so that all subsequent database queries still function properly and receive
     * the correct data.
     *
     * @param string              $command      The DataHandler command
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
        if ($command === 'delete') {
            $this->eventDispatcher
                ->dispatch(
                    new DataHandlerRecordDeleteEvent($table, $recordUid)
                );
        }
    }

    /**
     * Hooks into DataHandler and monitors all record movements.
     *
     * @param string              $command      The DataHandler command
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
        // TODO Handle workspaces?

        if ($command === 'move') {
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
     * Hooks into DataHandler and tracks all record movements.
     *
     * @param string       $table          The table currently processing data for
     * @param int          $uid            The record uid currently processing data for
     * @param int          $destPid        The target parent ID
     * @param array<mixed> $propArr        The record properties
     * @param array<mixed> $moveRec        The moved record
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
            // Track movement of record <TARGET PID> = <SOURCE PID>
            $this->recordMovements[$table][$destPid] = (int) $moveRec['pid'];
        }
    }
}
