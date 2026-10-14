<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Hook;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteRefusedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Hook\DataHandlerHook;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\RegistersEventSpyTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_map;
use function implode;
use function intval;

/**
 * Functional tests for the category handling and for the announcement of
 * moves, undeletes and deletes of DataHandlerHook, run through the real
 * DataHandler, so they depend on the order in which DataHandler writes
 * category assignments, checks permissions and calls the hooks.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DataHandlerHook::class)]
final class DataHandlerHookTest extends AbstractFunctionalTestCase
{
    use RegistersEventSpyTrait;

    /**
     * Imports the page tree, the page indexing service, an admin user and a
     * category assigned to pages 2 and 3.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_datahandler.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_record_mm_datahandler.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users.csv');

        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * Runs a DataHandler with the given data and commands.
     *
     * @param array<string, array<int|string, array<string, int|string>>> $dataMap           The records to save
     * @param array<string, array<int, array<string, int|string>>>        $cmdMap            The commands to execute
     * @param bool                                                        $mustSucceed       Whether DataHandler must not log an error
     * @param bool                                                        $bypassAccessCheck Whether DataHandler skips the access check of the records
     *
     * @return DataHandler The DataHandler after processing
     */
    private function runDataHandler(
        array $dataMap,
        array $cmdMap = [],
        bool $mustSucceed = true,
        bool $bypassAccessCheck = false,
    ): DataHandler {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->bypassAccessCheckForRecords = $bypassAccessCheck;
        $dataHandler->start($dataMap, $cmdMap);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();

        if ($mustSucceed) {
            self::assertSame([], $dataHandler->errorLog, implode(' | ', $dataHandler->errorLog));
        }

        return $dataHandler;
    }

    /**
     * Returns the UIDs of the pages currently in the indexing queue.
     *
     * @return list<int> The queued page UIDs in ascending order
     */
    private function findQueuedPageUids(): array
    {
        $queryBuilder = $this->getConnectionPool()
            ->getQueryBuilderForTable('tx_typo3searchalgolia_domain_model_queueitem');

        $recordUids = $queryBuilder
            ->select('record_uid')
            ->from('tx_typo3searchalgolia_domain_model_queueitem')
            ->where(
                $queryBuilder->expr()->eq(
                    'table_name',
                    $queryBuilder->createNamedParameter('pages')
                )
            )
            ->orderBy('record_uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(intval(...), $recordUids);
    }

    /**
     * Saving a category requeues its pages, including the page whose
     * assignment this very save removes from the category's items.
     */
    #[Test]
    public function savingACategoryRequeuesThePagesItsItemsNoLongerContain(): void
    {
        $this->runDataHandler([
            'sys_category' => [
                50 => [
                    'items' => 'pages_2',
                ],
            ],
        ]);

        self::assertSame([2, 3], $this->findQueuedPageUids());
    }

    /**
     * Saving a category leaves out its pages below a hidden subpage of a
     * recursively selected page tree, like the full queue rebuild does.
     */
    #[Test]
    public function savingACategoryLeavesOutPagesBelowAHiddenPage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_below_hidden.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_category_record_mm_below_hidden.csv');

        $this->runDataHandler([
            'sys_category' => [
                50 => [
                    'title' => 'Renamed Category',
                ],
            ],
        ]);

        self::assertSame([2, 3], $this->findQueuedPageUids());
    }

    /**
     * Creating a category requeues the pages it is assigned to on creation.
     */
    #[Test]
    public function creatingACategoryRequeuesItsPages(): void
    {
        $dataHandler = $this->runDataHandler([
            'sys_category' => [
                'NEW1' => [
                    'pid'   => 1,
                    'title' => 'New Category',
                    'items' => 'pages_3',
                ],
            ],
        ]);

        self::assertArrayHasKey('NEW1', $dataHandler->substNEWwithIDs);
        self::assertSame([3], $this->findQueuedPageUids());
    }

    /**
     * Deleting a category requeues its pages once it is deleted.
     */
    #[Test]
    public function deletingACategoryRequeuesItsPages(): void
    {
        $this->runDataHandler([], [
            'sys_category' => [
                50 => [
                    'delete' => 1,
                ],
            ],
        ]);

        self::assertSame([2, 3], $this->findQueuedPageUids());
    }

    /**
     * Deleting a record of another table whose uid equals the uid of a
     * category with assigned records does not requeue that category's records.
     */
    #[Test]
    public function deletingARecordOfAnotherTableRequeuesNoCategoryRecords(): void
    {
        // Page 50 does not exist, so it looks like a record that was just deleted
        $this->get(DataHandlerHook::class)->processCmdmap_postProcess(
            'delete',
            'pages',
            50,
            '',
            GeneralUtility::makeInstance(DataHandler::class),
        );

        self::assertSame([], $this->findQueuedPageUids());
    }

    /**
     * Another command on a category that is gone (e.g. a move) requeues
     * nothing, only an actual delete does.
     */
    #[Test]
    public function anotherCommandOnAMissingCategoryRequeuesNothing(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('sys_category')
            ->delete(
                'sys_category',
                [
                    'uid' => 50,
                ],
            );

        $this->get(DataHandlerHook::class)->processCmdmap_postProcess(
            'move',
            'sys_category',
            50,
            '',
            GeneralUtility::makeInstance(DataHandler::class),
        );

        self::assertSame([], $this->findQueuedPageUids());
    }

    /**
     * Deleting a category inside a workspace does not touch the live queue.
     */
    #[Test]
    public function deletingACategoryInAWorkspaceRequeuesNothing(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('sys_category')
            ->delete(
                'sys_category',
                [
                    'uid' => 50,
                ],
            );

        $GLOBALS['BE_USER']->workspace = 1;

        $this->get(DataHandlerHook::class)->processCmdmap_postProcess(
            'delete',
            'sys_category',
            50,
            '',
            GeneralUtility::makeInstance(DataHandler::class),
        );

        self::assertSame([], $this->findQueuedPageUids());
    }

    /**
     * A delete command that did not delete the category (e.g. refused for
     * missing permissions) requeues nothing. The hook is called directly with
     * the category still in place, as DataHandler does after a refused delete.
     */
    #[Test]
    public function aDeleteThatDidNotDeleteTheCategoryRequeuesNothing(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $this->get(DataHandlerHook::class)->processCmdmap_postProcess(
            'delete',
            'sys_category',
            50,
            '',
            $dataHandler,
        );

        self::assertSame([], $this->findQueuedPageUids());
    }

    /**
     * A record created through the DataHandler is announced as new, a record
     * that is saved again is not, so that the first one is not searched for a
     * document to remove.
     */
    #[Test]
    public function theUpdateEventTellsWhetherTheRecordWasCreated(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        $this->runDataHandler([
            'pages' => [
                'NEW1' => [
                    'pid'    => 1,
                    'title'  => 'Created page',
                    'hidden' => 0,
                ],
            ],
        ]);

        $this->runDataHandler([
            'pages' => [
                2 => [
                    'title' => 'Saved page',
                ],
            ],
        ]);

        $created = [];
        $saved   = [];

        foreach ($spy->events as $event) {
            if (!($event instanceof DataHandlerRecordUpdateEvent)) {
                continue;
            }

            if ($event->getTable() !== 'pages') {
                continue;
            }

            if ($event->getRecordUid() === 2) {
                $saved[] = $event->isNew();
            } else {
                $created[] = $event->isNew();
            }
        }

        self::assertSame([true], $created);
        self::assertSame([false], $saved);
    }

    /**
     * Switches to a backend user who may modify the tables, but has no page
     * mount, so DataHandler refuses every command on a record.
     *
     * @return void
     */
    private function actAsRestrictedUser(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_groups_editor_without_mount.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users_editor_without_mount.csv');

        $backendUser     = $this->setUpBackendUser(3);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * A move that DataHandler carries out is announced.
     */
    #[Test]
    public function anExecutedMoveIsAnnounced(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        $this->runDataHandler([], ['pages' => [3 => ['move' => 1]]]);

        self::assertCount(1, $spy->events, 'The carried out move is announced once');

        $event = $spy->events[0];

        self::assertInstanceOf(DataHandlerRecordMoveEvent::class, $event);
        self::assertSame('pages', $event->getTable());
        self::assertSame(3, $event->getRecordUid());
        self::assertSame(1, $event->getTargetPid());
        self::assertSame(2, $event->getPreviousPid(), 'The former parent of the moved page');
    }

    /**
     * A page that is placed after another page is announced with the parent of
     * that page as its target, and not with the negative UID of the page the
     * command carries.
     */
    #[Test]
    public function aMoveAfterAPageIsAnnouncedWithTheNewParent(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        // Page 6 is a child of page 1, page 3 is a child of page 2
        $this->runDataHandler([], ['pages' => [3 => ['move' => -6]]]);

        self::assertCount(1, $spy->events);

        $event = $spy->events[0];

        self::assertInstanceOf(DataHandlerRecordMoveEvent::class, $event);
        self::assertSame(1, $event->getTargetPid(), 'The parent of the page it was placed after');
        self::assertSame(2, $event->getPreviousPid());
    }

    /**
     * A page that is placed after a page of its own parent stays below that
     * parent, so the announced target equals the former parent and the listener
     * can tell that the page did not change its place in the tree.
     */
    #[Test]
    public function aMoveAfterASiblingIsAnnouncedWithTheSameParent(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        // Pages 2 and 6 are both children of page 1
        $this->runDataHandler([], ['pages' => [2 => ['move' => -6]]]);

        self::assertCount(1, $spy->events);

        $event = $spy->events[0];

        self::assertInstanceOf(DataHandlerRecordMoveEvent::class, $event);
        self::assertSame(1, $event->getTargetPid());
        self::assertSame(1, $event->getPreviousPid());
    }

    /**
     * A user of a workspace without the live edit flag cannot move a record of a table
     * that has no versions, because the workspace blocks the move. The record stays
     * where it is, so the command is not announced as a move, while the same command
     * in a workspace that allows the live edit is.
     *
     * @param bool $liveEdit  Whether the workspace of the user allows the live edit
     * @param int  $announced The number of announced moves
     */
    #[Test]
    #[DataProvider('workspacesWithAndWithoutLiveEdit')]
    public function aMoveOfARecordWithoutVersionsIsAnnouncedOnlyWhenTheWorkspaceAllowsTheLiveEdit(
        bool $liveEdit,
        int $announced,
    ): void {
        $spy         = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], []);

        // A table without a TCA definition has no versions
        $table = 'tx_typo3searchalgolia_domain_model_queueitem';

        $this->getConnectionPool()
            ->getConnectionForTable($table)
            ->insert($table, ['uid' => 900, 'pid' => 0, 'table_name' => 'pages', 'record_uid' => 2, 'service_uid' => 1]);

        $GLOBALS['BE_USER']->workspace    = 1;
        $GLOBALS['BE_USER']->workspaceRec = ['live_edit' => $liveEdit ? 1 : 0];

        $recordWasMoved = false;

        $hook->moveRecord($table, 900, 0, ['pid' => 0], ['pid' => 0], 0, $recordWasMoved, $dataHandler);
        $hook->processCmdmap_postProcess('move', $table, 900, '0', $dataHandler);

        self::assertCount($announced, $spy->events);
    }

    /**
     * Provides workspaces with and without the live edit flag.
     *
     * @return iterable<string, array{bool, int}>
     */
    public static function workspacesWithAndWithoutLiveEdit(): iterable
    {
        yield 'live edit blocked' => [false, 0];
        yield 'live edit allowed' => [true, 1];
    }

    /**
     * A move command that names the workspace version of a live page moves the live
     * page, which DataHandler tells the move hook by the UID of the live page. The
     * announcement carries the live page and the parent it had, not the version the
     * command named.
     */
    #[Test]
    public function aMoveThatNamesTheVersionOfALivePageIsAnnouncedForTheLivePage(): void
    {
        $this->insertPageVersion(7, 3, 0, 2);

        $spy         = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], []);

        // DataHandler resolves the version to the live page before it calls the hook
        $recordWasMoved = false;
        $hook->moveRecord('pages', 3, 1, [], ['pid' => 2], 1, $recordWasMoved, $dataHandler);
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 1], ['uid' => 3]);
        $hook->processCmdmap_postProcess('move', 'pages', 7, '1', $dataHandler);

        self::assertCount(1, $spy->events, 'The move of the live page is announced once');

        $event = $spy->events[0];

        self::assertInstanceOf(DataHandlerRecordMoveEvent::class, $event);
        self::assertSame(3, $event->getRecordUid(), 'The live page and not its version');
        self::assertSame(1, $event->getTargetPid());
        self::assertSame(2, $event->getPreviousPid());
    }

    /**
     * A content element that is moved to another page is announced with the page
     * it came from, so that the former page can be queued again.
     */
    #[Test]
    public function aMovedContentElementIsAnnouncedWithItsFormerPage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');
        $spy = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        // Content element 1 lives on page 2
        $this->runDataHandler([], ['tt_content' => [1 => ['move' => 3]]]);

        self::assertCount(1, $spy->events);

        $event = $spy->events[0];

        self::assertInstanceOf(DataHandlerRecordMoveEvent::class, $event);
        self::assertSame('tt_content', $event->getTable());
        self::assertSame(3, $event->getTargetPid());
        self::assertSame(2, $event->getPreviousPid(), 'The page the element came from');
    }

    /**
     * A move of a record that cannot be read afterwards is not announced, so no
     * target that DataHandler never stored reaches the listeners.
     */
    #[Test]
    public function aMoveOfARecordThatIsGoneIsNotAnnounced(): void
    {
        $spy         = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], []);

        // DataHandler called the move hook for page 9999, which does not exist afterwards
        $recordWasMoved = false;
        $hook->moveRecord('pages', 9999, -6, [], ['pid' => 2], 1, $recordWasMoved, $dataHandler);
        $hook->processCmdmap_postProcess('move', 'pages', 9999, '-6', $dataHandler);

        self::assertSame([], $spy->events);
    }

    /**
     * A move that DataHandler refuses is not announced, so that no document is
     * removed for a record the user had no right to touch.
     */
    #[Test]
    public function aRefusedMoveIsNotAnnounced(): void
    {
        $this->actAsRestrictedUser();
        $spy = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        $dataHandler = $this->runDataHandler([], ['pages' => [3 => ['move' => 1]]], false);

        self::assertNotSame([], $dataHandler->errorLog);
        self::assertSame([], $spy->events);
    }

    /**
     * An undelete that brings a deleted record back is announced as an update.
     */
    #[Test]
    public function anUndeleteOfADeletedRecordIsAnnounced(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_deleted.csv');
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        $this->runDataHandler([], ['pages' => [8 => ['undelete' => 1]]]);

        self::assertCount(1, $spy->events, 'The undeleted record is announced once');

        $event = $spy->events[0];

        self::assertInstanceOf(DataHandlerRecordUpdateEvent::class, $event);
        self::assertSame('pages', $event->getTable());
        self::assertSame(8, $event->getRecordUid());
    }

    /**
     * An undelete command for a record that is not deleted changes nothing and
     * is not announced, because nothing came back. The record was live before
     * the command, whoever sends it.
     */
    #[Test]
    public function anUndeleteOfALiveRecordIsNotAnnounced(): void
    {
        $this->actAsRestrictedUser();
        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        $this->runDataHandler([], ['pages' => [3 => ['undelete' => 1]]], false);

        self::assertSame([], $spy->events);
    }

    /**
     * A delete that DataHandler carries out is announced as deleted.
     */
    #[Test]
    public function anExecutedDeleteIsAnnouncedAsDeleted(): void
    {
        $spy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        // A table without an indexing service, so that no search engine is called
        $this->runDataHandler([], ['sys_category' => [50 => ['delete' => 1]]]);

        self::assertCount(1, $spy->events, 'The deleted record is announced once');

        $event = $spy->events[0];

        self::assertInstanceOf(DataHandlerRecordDeletedEvent::class, $event);
        self::assertSame('sys_category', $event->getTable());
        self::assertSame(50, $event->getRecordUid());
    }

    /**
     * A delete that DataHandler refuses is announced as refused, so that the
     * context that was collected for it before the permission check is
     * discarded, and not as deleted.
     */
    #[Test]
    public function aRefusedDeleteIsAnnouncedAsRefused(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');
        $this->actAsRestrictedUser();

        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        $this->runDataHandler([], ['tt_content' => [1 => ['delete' => 1]]], false);

        self::assertCount(1, $refusedSpy->events, 'The refused delete is announced once');

        $event = $refusedSpy->events[0];

        self::assertInstanceOf(DataHandlerRecordDeleteRefusedEvent::class, $event);
        self::assertSame('tt_content', $event->getTable());
        self::assertSame(1, $event->getRecordUid());
        self::assertSame([], $deletedSpy->events);

        // The content element is still live, so DataHandler did refuse the command
        self::assertSame(0, (int) ($this->fetchFirstRowByFieldValue('tt_content', 'uid', 1)['deleted'] ?? 1));
    }

    /**
     * A delete that DataHandler carries out is announced as deleted only, so
     * that the collected context is used and not discarded.
     */
    #[Test]
    public function anExecutedDeleteIsNotAnnouncedAsRefused(): void
    {
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);

        $this->runDataHandler([], ['sys_category' => [50 => ['delete' => 1]]]);

        self::assertSame([], $refusedSpy->events);
    }

    /**
     * A delete command for a record that is deleted already changes nothing,
     * so it is announced as refused and the context that was collected for it
     * is discarded.
     */
    #[Test]
    public function aDeleteOfAnAlreadyDeletedRecordIsAnnouncedAsRefused(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_deleted.csv');
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        $this->runDataHandler([], ['pages' => [8 => ['delete' => 1]]], false);

        self::assertCount(1, $refusedSpy->events);
        self::assertSame([], $deletedSpy->events);
    }

    /**
     * A delete of a page that the user has no delete permission for is refused by
     * DataHandler for certain, so the tree of the page is not collected for it. The
     * command is still announced as refused, and the documents of the page stay.
     */
    #[Test]
    public function aDeleteOfAPageThatIsRefusedForSureCollectsNoTreeAndIsAnnouncedAsRefused(): void
    {
        $this->actAsRestrictedUser();

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        $this->runDataHandler([], ['pages' => [3 => ['delete' => 1]]], false);

        self::assertSame([], $deleteSpy->events, 'No context is collected for a delete that is refused for sure');
        self::assertCount(1, $refusedSpy->events, 'And announced as refused');
        self::assertSame([], $deletedSpy->events);
    }

    /**
     * Switches to a backend user with a page mount who may modify the tables,
     * and sets the permissions that everybody has on page 3.
     *
     * @param int $pagePermissions The permission bits everybody has on page 3
     *
     * @return void
     */
    private function actAsEditorWithPermissions(int $pagePermissions): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_groups_editor_without_mount.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users_editor_with_mount.csv');

        // The mount page and the pages on the way to page 3 have to be visible for the user
        $pagesConnection = $this->getConnectionPool()->getConnectionForTable('pages');
        $pagesConnection->update('pages', ['perms_everybody' => Permission::PAGE_SHOW], ['uid' => 1]);
        $pagesConnection->update('pages', ['perms_everybody' => Permission::PAGE_SHOW], ['uid' => 2]);
        $pagesConnection->update('pages', ['perms_everybody' => $pagePermissions], ['uid' => 3]);

        // Without indexing services nothing reaches a search engine
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->truncate('tx_typo3searchalgolia_domain_model_indexingservice');

        $backendUser     = $this->setUpBackendUser(6);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * A user who may see and edit a page, but not delete it, sends a command that
     * DataHandler refuses afterwards, so it is announced as refused.
     */
    #[Test]
    public function aDeleteOfAPageWithoutTheDeleteBitIsAnnouncedAsRefused(): void
    {
        // Show, edit page and edit content, no delete
        $this->actAsEditorWithPermissions(Permission::PAGE_SHOW | Permission::PAGE_EDIT | Permission::CONTENT_EDIT);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);

        $this->runDataHandler([], ['pages' => [3 => ['delete' => 1]]], false);

        self::assertSame([], $deleteSpy->events, 'The edit permission is no delete permission, nothing is collected');
        self::assertSame([], $deletedSpy->events);
        self::assertCount(1, $refusedSpy->events);
    }

    /**
     * The permission to delete a page is looked at for a page only. A content element
     * whose UID is the one of a page that the user may not delete is collected.
     */
    #[Test]
    public function aContentElementWithTheUidOfAPageThatMayNotBeDeletedIsCollected(): void
    {
        $this->actAsEditorWithPermissions(Permission::PAGE_SHOW | Permission::PAGE_EDIT | Permission::CONTENT_EDIT);

        $deleteSpy   = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], [], $GLOBALS['BE_USER']);

        // Page 3 has no delete permission for the user
        $hook->processCmdmap_preProcess('delete', 'tt_content', 3, '', $dataHandler);

        self::assertCount(1, $deleteSpy->events);
    }

    /**
     * The translation of a page is checked against its default language page by
     * DataHandler, so a translation is collected for a user who has no permission
     * for the translation itself.
     */
    #[Test]
    public function aTranslationOfAPageIsCollectedWithoutLookingAtItsOwnPermissions(): void
    {
        $this->actAsEditorWithPermissions(
            Permission::PAGE_SHOW | Permission::PAGE_EDIT | Permission::CONTENT_EDIT | Permission::PAGE_DELETE
        );

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'              => 30,
                    'pid'              => 2,
                    'title'            => 'Translation',
                    'doktype'          => 1,
                    'sys_language_uid' => 1,
                    'l10n_parent'      => 3,
                ]
            );

        $deleteSpy   = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], [], $GLOBALS['BE_USER']);

        // The translation has no permission bits of its own for the user
        $hook->processCmdmap_preProcess('delete', 'pages', 30, '', $dataHandler);

        self::assertCount(1, $deleteSpy->events);
    }

    /**
     * A user with the delete bit on the page announces the delete, so that its
     * context is collected and the removal follows the deletion.
     */
    #[Test]
    public function aDeleteOfAPageWithTheDeleteBitIsCollectedAndAnnouncedAsDeleted(): void
    {
        $this->actAsEditorWithPermissions(
            Permission::PAGE_SHOW | Permission::PAGE_EDIT | Permission::CONTENT_EDIT | Permission::PAGE_DELETE
        );

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        $this->runDataHandler([], ['pages' => [3 => ['delete' => 1]]]);

        self::assertCount(1, $deleteSpy->events, 'The delete of page 3 is collected once');
        self::assertCount(1, $deletedSpy->events, 'And announced as deleted once');
    }

    /**
     * A DataHandler that skips the access check of the records passes the check
     * of the page itself for any user, so the delete is collected although the
     * global backend user has no delete permission. The check of the subpages is
     * not skipped by the flag, page 3 has none. Otherwise the documents of the
     * page would stay.
     */
    #[Test]
    public function aDeleteThatSkipsTheAccessCheckIsCollected(): void
    {
        $this->actAsEditorWithPermissions(Permission::PAGE_SHOW | Permission::PAGE_EDIT | Permission::CONTENT_EDIT);

        $deleteSpy = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);

        $this->runDataHandler([], ['pages' => [3 => ['delete' => 1]]], false, true);

        self::assertCount(1, $deleteSpy->events, 'The delete of page 3 is collected once');
    }

    /**
     * Commands in a workspace announce nothing, because the live record and
     * its documents are not touched by them.
     */
    #[Test]
    public function aDeleteAndAMoveInAWorkspaceAreNotAnnounced(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $moveSpy    = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        $this->runDataHandler([], ['pages' => [3 => ['delete' => 1]]], false);
        $this->runDataHandler([], ['pages' => [3 => ['move' => 1]]], false);

        self::assertSame([], $deleteSpy->events);
        self::assertSame([], $deletedSpy->events);
        self::assertSame([], $refusedSpy->events);
        self::assertSame([], $moveSpy->events);
    }

    /**
     * The same delete sent twice announces the record as deleted once, the
     * second command finds nothing that was live before it.
     */
    #[Test]
    public function aDeleteSentTwiceIsAnnouncedAsDeletedOnce(): void
    {
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        $this->runDataHandler([], ['sys_category' => [50 => ['delete' => 1]]]);
        $this->runDataHandler([], ['sys_category' => [50 => ['delete' => 1]]], false);

        self::assertCount(1, $deletedSpy->events);
    }

    /**
     * A DataHandler that acts for a user of the live workspace announces its
     * commands although the global backend user works in a workspace, because
     * the commands are carried out for the user of the DataHandler. This holds
     * for the delete, which is collected before and announced after the command,
     * for a move and for the requeue of a category.
     */
    #[Test]
    public function aLiveUserOfTheDataHandlerIsAnnouncedWhileTheGlobalUserWorksInAWorkspace(): void
    {
        $liveUser = $this->setUpBackendUser(1);

        $globalUser            = $this->setUpBackendUser(1);
        $globalUser->workspace = 1;

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $moveSpy    = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        $this->runForUser($liveUser, [], ['sys_category' => [50 => ['delete' => 1]]]);
        $this->runForUser($liveUser, [], ['pages' => [3 => ['move' => 1]]]);

        self::assertCount(1, $deleteSpy->events, 'The delete is collected for the user of the DataHandler');
        self::assertCount(1, $deletedSpy->events, 'And announced as deleted after the command');
        self::assertCount(1, $moveSpy->events, 'The move is announced after the command');
        self::assertSame([2, 3], $this->findQueuedPageUids(), 'The category requeued its pages once deleted');
    }

    /**
     * A DataHandler that acts for a user of the live workspace requeues the
     * pages of a category it saves, while the global backend user works in a
     * workspace. Both datamap hooks read the user of the DataHandler.
     */
    #[Test]
    public function aCategorySavedForALiveUserIsRequeuedWhileTheGlobalUserWorksInAWorkspace(): void
    {
        $liveUser = $this->setUpBackendUser(1);

        $globalUser            = $this->setUpBackendUser(1);
        $globalUser->workspace = 1;

        // The save removes page 3 from the category, which is only known from the assignment
        // that was remembered before the save
        $this->runForUser($liveUser, ['sys_category' => [50 => ['items' => 'pages_2']]], []);

        self::assertSame([2, 3], $this->findQueuedPageUids());
    }

    /**
     * A DataHandler that acts for a user of the live workspace requeues the
     * pages of a category it creates, while the global backend user works in a
     * workspace. The assignments of a new category are only known after the save.
     */
    #[Test]
    public function aCategoryCreatedForALiveUserIsRequeuedWhileTheGlobalUserWorksInAWorkspace(): void
    {
        $liveUser = $this->setUpBackendUser(1);

        $globalUser            = $this->setUpBackendUser(1);
        $globalUser->workspace = 1;

        $this->runForUser(
            $liveUser,
            ['sys_category' => ['NEW1' => ['pid' => 1, 'title' => 'New Category', 'items' => 'pages_3']]],
            []
        );

        self::assertSame([3], $this->findQueuedPageUids());
    }

    /**
     * A DataHandler that acts for a user of a workspace announces nothing,
     * although the global backend user works in the live workspace, because the
     * commands of that user do not touch the live record. The delete, the move
     * and the requeue of a saved category are covered.
     */
    #[Test]
    public function aWorkspaceUserOfTheDataHandlerAnnouncesNothingWhileTheGlobalUserIsLive(): void
    {
        $workspaceUser            = $this->setUpBackendUser(1);
        $workspaceUser->workspace = 1;

        $this->setUpBackendUser(1);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $moveSpy    = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        $this->runForUser($workspaceUser, [], ['sys_category' => [50 => ['delete' => 1]]]);
        $this->runForUser($workspaceUser, [], ['pages' => [3 => ['move' => 1]]]);
        $this->runForUser($workspaceUser, ['sys_category' => [50 => ['items' => 'pages_2']]], []);

        self::assertSame([], $deleteSpy->events);
        self::assertSame([], $deletedSpy->events);
        self::assertSame([], $refusedSpy->events);
        self::assertSame([], $moveSpy->events);
        self::assertSame([], $this->findQueuedPageUids());
    }

    /**
     * Returns whether a record is marked as deleted.
     *
     * @param string $table The table of the record
     * @param int    $uid   The UID of the record
     *
     * @return bool TRUE if the record is marked as deleted
     */
    private function isMarkedAsDeleted(
        string $table,
        int $uid,
    ): bool {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);

        $queryBuilder->getRestrictions()->removeAll();

        $deleted = $queryBuilder
            ->select('deleted')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid)
                )
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $deleted === 1;
    }

    /**
     * An undelete that DataHandler refuses, because the page that holds the
     * record is deleted itself, is not announced. The record stays deleted, so
     * there is no document to bring back.
     */
    #[Test]
    public function anUndeleteOfARecordBelowADeletedPageIsNotAnnounced(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_deleted.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1, 'pid' => 8], ['uid' => 1]);

        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        $this->runDataHandler([], ['tt_content' => [1 => ['undelete' => 1]]], false);

        self::assertTrue($this->isMarkedAsDeleted('tt_content', 1), 'DataHandler left the record deleted');
        self::assertSame([], $spy->events);
    }

    /**
     * An undelete by a user who works in a workspace does not bring the live
     * record back. DataHandler leaves the record deleted, which is what the
     * hook goes by, so nothing is announced.
     */
    #[Test]
    public function anUndeleteByAUserInAWorkspaceIsNotAnnounced(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_deleted.csv');

        $GLOBALS['BE_USER']->workspace = 1;

        $spy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);

        $this->runDataHandler([], ['pages' => [8 => ['undelete' => 1]]], false);

        self::assertTrue($this->isMarkedAsDeleted('pages', 8), 'DataHandler left the record deleted');
        self::assertSame([], $spy->events);
    }

    /**
     * The hook never calls cannotDeleteRecord() of DataHandler for the delete of a
     * page, for an admin and for any other user. A delete that the user has no
     * permission for is left out of the collection, see the tests of the permission.
     */
    #[Test]
    public function aDeleteOfAPageNeverAsksDataHandlerWhetherItRefuses(): void
    {
        $deleteSpy = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $hook      = $this->get(DataHandlerHook::class);

        $dataHandler = new class extends DataHandler {
            /**
             * The number of times the hook asked DataHandler whether it refuses the delete.
             */
            public int $refusalChecks = 0;

            /**
             * Counts the question and leaves the answer of DataHandler alone.
             *
             * @param string $table The table of the record
             * @param int    $id    The UID of the record
             *
             * @return string
             */
            public function cannotDeleteRecord(
                $table,
                $id,
            ) {
                ++$this->refusalChecks;

                return parent::cannotDeleteRecord($table, $id);
            }
        };

        // The user of the test is an admin
        $dataHandler->start([], []);

        $hook->processCmdmap_preProcess('delete', 'pages', 3, '', $dataHandler);

        self::assertCount(1, $deleteSpy->events);

        $this->actAsEditorWithPermissions(
            Permission::PAGE_SHOW | Permission::PAGE_EDIT | Permission::CONTENT_EDIT | Permission::PAGE_DELETE
        );

        $dataHandler->start([], [], $GLOBALS['BE_USER']);
        $hook->processCmdmap_preProcess('delete', 'pages', 3, '', $dataHandler);

        self::assertCount(2, $deleteSpy->events);
        self::assertSame(0, $dataHandler->refusalChecks, 'DataHandler is never asked');
    }

    /**
     * What a move command left behind in the shared hook is forgotten when
     * DataHandler finished all commands. DataHandler calls the move hook for
     * records that get no post-process call, and such a marker must not be
     * taken for the move of a later command.
     */
    #[Test]
    public function aMoveMarkerThatNoPostProcessConsumedIsForgottenAfterTheCommands(): void
    {
        $spy         = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], []);

        // The move hook ran for page 3, but no post-process call follows for it
        $recordWasMoved = false;
        $hook->moveRecord('pages', 3, -6, [], ['pid' => 2], 1, $recordWasMoved, $dataHandler);
        $hook->processCmdmap_afterFinish($dataHandler);

        $hook->processCmdmap_postProcess('move', 'pages', 3, '-6', $dataHandler);

        self::assertSame([], $spy->events, 'The forgotten marker announces nothing');

        // A move of a later command is announced as before
        $hook->moveRecord('pages', 3, -6, [], ['pid' => 2], 1, $recordWasMoved, $dataHandler);
        $hook->processCmdmap_postProcess('move', 'pages', 3, '-6', $dataHandler);

        self::assertCount(1, $spy->events);
    }

    /**
     * The parent that a published workspace move remembered is forgotten when
     * DataHandler finished all commands, so a later version command for the same
     * record is not taken for the carried out publish.
     */
    #[Test]
    public function aPublishedMoveMarkerThatNoPostProcessConsumedIsForgottenAfterTheCommands(): void
    {
        $this->insertPageVersion(7, 3, 4, 4);

        $spy         = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $command     = ['action' => 'swap', 'swapWith' => 7];

        $dataHandler->start([], []);

        $hook->processCmdmap_preProcess('version', 'pages', 3, $command, $dataHandler);
        $hook->processCmdmap_afterFinish($dataHandler);

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 4], ['uid' => 3]);

        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertSame([], $spy->events);
    }

    /**
     * A key that DataHandler reads as a UID is a command for that record, the way
     * DataHandler casts it for a delete, so the hook follows the same reading and
     * does not leave the documents of a record that was really deleted.
     */
    #[Test]
    public function aDeleteWithAKeyThatDataHandlerReadsAsAUidIsCollectedAndAnnounced(): void
    {
        // Without indexing services nothing reaches a search engine
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->truncate('tx_typo3searchalgolia_domain_model_indexingservice');

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        // Page 3 is read as UID 3 from the key, and is deleted afterwards
        $hook->processCmdmap_preProcess('delete', 'pages', '3abc', '', $dataHandler);

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 3]);

        $hook->processCmdmap_postProcess('delete', 'pages', '3abc', '', $dataHandler);

        self::assertCount(1, $deleteSpy->events);
        self::assertCount(1, $deletedSpy->events);
    }

    /**
     * A key that reads as no record at all, a word or a number below one, is no
     * command for a record, and neither is a non-numeric key of a version command.
     */
    #[Test]
    public function aCommandForAKeyThatReadsAsNoRecordIsIgnored(): void
    {
        // The delete placeholder that the version command names exists, so only the key
        // keeps the command from being taken for the publish of the placeholder
        $this->insertPageVersion(7, 3, 2, 2);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        foreach (['abc', 'NEW123', 0, '0', -3] as $key) {
            $hook->processCmdmap_preProcess('delete', 'tt_content', $key, '', $dataHandler);
            $hook->processCmdmap_postProcess('delete', 'tt_content', $key, '', $dataHandler);
        }

        $versionCommand = ['action' => 'swap', 'swapWith' => 7];

        $hook->processCmdmap_preProcess('version', 'pages', '3abc', $versionCommand, $dataHandler);
        $hook->processCmdmap_postProcess('version', 'pages', '3abc', $versionCommand, $dataHandler);

        self::assertSame([], $deleteSpy->events);
        self::assertSame([], $refusedSpy->events);
    }

    /**
     * What a delete or an undelete command left behind in the shared hook is
     * forgotten when DataHandler finished all commands, so a later command for
     * the same record is not taken for the carried out one.
     */
    #[Test]
    public function aDeleteAndAnUndeleteMarkerThatNoPostProcessConsumedAreForgottenAfterTheCommands(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');

        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $updateSpy  = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // A delete that was remembered for element 1, and an undelete for the deleted element 2
        $connection->update('tt_content', ['deleted' => 1], ['uid' => 2]);

        $hook->processCmdmap_preProcess('delete', 'tt_content', 1, '', $dataHandler);
        $hook->processCmdmap_preProcess('undelete', 'tt_content', 2, '', $dataHandler);
        $hook->processCmdmap_afterFinish($dataHandler);

        // The records change through something else later on, and the post-process of a later command asks again
        $connection->update('tt_content', ['deleted' => 1], ['uid' => 1]);
        $connection->update('tt_content', ['deleted' => 0], ['uid' => 2]);

        $hook->processCmdmap_postProcess('delete', 'tt_content', 1, '', $dataHandler);
        $hook->processCmdmap_postProcess('undelete', 'tt_content', 2, '', $dataHandler);

        self::assertSame([], $deletedSpy->events, 'The forgotten delete is not announced as carried out');
        self::assertCount(1, $refusedSpy->events, 'It is announced as one that changed nothing');
        self::assertSame([], $updateSpy->events, 'The forgotten undelete is not announced');
    }

    /**
     * Inserts the workspace version of a page, the way a workspace delete or a
     * workspace move leaves it behind until it is published.
     *
     * @param int $uid        The UID of the version record
     * @param int $livePageId The UID of the live page it belongs to
     * @param int $state      The version state, 2 for a delete placeholder and 4 for a move pointer
     * @param int $pid        The parent of the version record
     *
     * @return void
     */
    private function insertPageVersion(
        int $uid,
        int $livePageId,
        int $state,
        int $pid,
    ): void {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => $uid,
                    'pid'         => $pid,
                    'title'       => 'Version of ' . $livePageId,
                    'doktype'     => 1,
                    't3ver_oid'   => $livePageId,
                    't3ver_wsid'  => 1,
                    't3ver_state' => $state,
                ]
            );
    }

    /**
     * The publish of a workspace delete deletes the live page through the
     * version command and not through a delete command, so it has to be
     * collected before and announced as deleted afterwards.
     */
    #[Test]
    public function aPublishedWorkspaceDeleteIsCollectedAndAnnouncedAsDeleted(): void
    {
        $this->insertPageVersion(7, 3, 2, 2);

        // Without indexing services nothing reaches a search engine
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->truncate('tx_typo3searchalgolia_domain_model_indexingservice');

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $GLOBALS['BE_USER']->workspace = 1;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $command = ['action' => 'swap', 'swapWith' => 7];

        $hook->processCmdmap_preProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertCount(1, $deleteSpy->events, 'The delete of the live page is collected before the swap');

        // The workspaces hook deletes the live page while it swaps
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 3]);

        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertCount(1, $deletedSpy->events, 'And announced as deleted afterwards');
    }

    /**
     * A publish that leaves the live page in place, for example one that the user
     * may not publish, announces the delete as refused so the collected context
     * is discarded.
     */
    #[Test]
    public function aPublishOfAWorkspaceDeleteThatDeletedNothingIsAnnouncedAsRefused(): void
    {
        $this->insertPageVersion(7, 3, 2, 2);

        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $GLOBALS['BE_USER']->workspace = 1;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $command = ['action' => 'swap', 'swapWith' => 7];

        $hook->processCmdmap_preProcess('version', 'pages', 3, $command, $dataHandler);
        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertSame([], $deletedSpy->events);
        self::assertCount(1, $refusedSpy->events);
    }

    /**
     * The publish of a workspace move changes the parent of the live page through
     * the version command, so the move is announced with the former parent.
     */
    #[Test]
    public function aPublishedWorkspaceMoveIsAnnouncedWithItsFormerParent(): void
    {
        // The move pointer of page 3 names page 4 as the new parent
        $this->insertPageVersion(7, 3, 4, 4);

        $spy  = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook = $this->get(DataHandlerHook::class);

        $GLOBALS['BE_USER']->workspace = 1;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $command = ['action' => 'swap', 'swapWith' => 7];

        $hook->processCmdmap_preProcess('version', 'pages', 3, $command, $dataHandler);

        // The swap gives the live page the parent of the move pointer
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 4], ['uid' => 3]);

        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertCount(1, $spy->events);

        /** @var DataHandlerRecordMoveEvent $event */
        $event = $spy->events[0];

        self::assertSame(4, $event->getTargetPid());
        self::assertSame(2, $event->getPreviousPid(), 'The parent before the publish');
    }

    /**
     * The publish of a changed record announces neither a delete nor a move, it
     * is handled through the event of the published record.
     */
    #[Test]
    public function aPublishedWorkspaceChangeAnnouncesNeitherDeleteNorMove(): void
    {
        $this->insertPageVersion(7, 3, 0, 2);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $moveSpy    = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $GLOBALS['BE_USER']->workspace = 1;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $command = ['action' => 'swap', 'swapWith' => 7];

        $hook->processCmdmap_preProcess('version', 'pages', 3, $command, $dataHandler);
        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertSame([], $deleteSpy->events);
        self::assertSame([], $refusedSpy->events);
        self::assertSame([], $moveSpy->events);
    }

    /**
     * A delete command for a table that is not workspace-enabled is announced
     * although the global backend user works in a workspace, because the
     * command changes the live record. The table has no workspace support, so
     * the hook is called as DataHandler would call it for any table of that kind.
     */
    #[Test]
    public function aDeleteInATableWithoutWorkspaceSupportIsAnnouncedInAWorkspace(): void
    {
        // The workspace allows the live edit of tables without versions
        $GLOBALS['BE_USER']->workspace    = 1;
        $GLOBALS['BE_USER']->workspaceRec = ['live_edit' => 1];

        $table = 'tx_typo3searchalgolia_domain_model_queueitem';

        $this->getConnectionPool()
            ->getConnectionForTable($table)
            ->insert($table, ['uid' => 900, 'pid' => 0, 'table_name' => 'pages', 'record_uid' => 2, 'service_uid' => 1]);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $hook->processCmdmap_preProcess('delete', $table, 900, '', $dataHandler);

        $this->getConnectionPool()
            ->getConnectionForTable($table)
            ->delete($table, ['uid' => 900]);

        $hook->processCmdmap_postProcess('delete', $table, 900, '', $dataHandler);

        self::assertCount(1, $deleteSpy->events, 'The delete is collected');
        self::assertCount(1, $deletedSpy->events, 'And announced as deleted');
    }

    /**
     * The same delete command for a workspace-enabled table is not announced
     * while the global backend user works in a workspace, because the live
     * record is not touched.
     */
    #[Test]
    public function aDeleteInAWorkspaceEnabledTableIsNotAnnouncedInAWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $hook->processCmdmap_preProcess('delete', 'pages', 3, '', $dataHandler);

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 3]);

        $hook->processCmdmap_postProcess('delete', 'pages', 3, '', $dataHandler);

        self::assertSame([], $deleteSpy->events);
        self::assertSame([], $deletedSpy->events);
    }

    /**
     * Runs the pre-process and the post-process hook of a version command, the way
     * DataHandler calls them for the publish of a workspace version.
     *
     * @param string                           $table   The table of the live record
     * @param int                              $uid     The UID of the live record
     * @param array<string, int|string>|string $command The value of the version command
     * @param callable(): void|null            $between Runs between the two hooks and changes the live record the way the publish does
     *
     * @return void
     */
    private function publishVersion(
        string $table,
        int $uid,
        array|string $command,
        ?callable $between = null,
    ): void {
        $hook = $this->get(DataHandlerHook::class);

        $GLOBALS['BE_USER']->workspace = 1;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $hook->processCmdmap_preProcess('version', $table, $uid, $command, $dataHandler);

        if ($between !== null) {
            $between();
        }

        $hook->processCmdmap_postProcess('version', $table, $uid, $command, $dataHandler);
    }

    /**
     * The publish of a delete placeholder by a user who may not edit the live record is
     * refused for sure, so no tree is collected for it. The command is still announced
     * as refused.
     */
    #[Test]
    public function aPublishOfADeletePlaceholderThatTheUserMayNotPublishCollectsNoTree(): void
    {
        $this->actAsRestrictedUser();
        $this->insertPageVersion(7, 3, 2, 2);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);

        $this->publishVersion('pages', 3, ['action' => 'publish', 'swapWith' => 7]);

        self::assertSame([], $deleteSpy->events, 'No context is collected for a publish that is refused for sure');
        self::assertCount(1, $refusedSpy->events, 'And it is announced as refused');
    }

    /**
     * A version command whose action is neither swap nor publish does not change
     * the live record, so no delete is collected for it even if the version is a
     * delete placeholder.
     *
     * @param string $action The action of the version command
     */
    #[Test]
    #[DataProvider('versionActionsThatDoNotPublish')]
    public function aVersionCommandThatDoesNotPublishCollectsNothing(string $action): void
    {
        $this->insertPageVersion(7, 3, 2, 2);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);

        $this->publishVersion('pages', 3, ['action' => $action, 'swapWith' => 7]);

        self::assertSame([], $deleteSpy->events);
        self::assertSame([], $deletedSpy->events);
        self::assertSame([], $refusedSpy->events);
    }

    /**
     * Provides actions of the version command that leave the live record alone.
     *
     * @return iterable<string, array{string}>
     */
    public static function versionActionsThatDoNotPublish(): iterable
    {
        yield 'a version leaves its workspace' => ['clearWSID'];
        yield 'a version is flushed' => ['flush'];
        yield 'the stage of a version changes' => ['setStage'];
    }

    /**
     * The publish action of a version command changes the live record like the
     * swap action, so the delete of a delete placeholder is collected and
     * announced as well.
     */
    #[Test]
    public function aPublishActionIsHandledLikeASwapAction(): void
    {
        $this->insertPageVersion(7, 3, 2, 2);

        // Without indexing services nothing reaches a search engine
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->truncate('tx_typo3searchalgolia_domain_model_indexingservice');

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        $this->publishVersion(
            'pages',
            3,
            ['action' => 'publish', 'swapWith' => 7],
            function (): void {
                $this->getConnectionPool()
                    ->getConnectionForTable('pages')
                    ->update('pages', ['deleted' => 1], ['uid' => 3]);
            }
        );

        self::assertCount(1, $deleteSpy->events, 'The delete is collected before the publish');
        self::assertCount(1, $deletedSpy->events, 'And announced as deleted afterwards');
    }

    /**
     * A version command that names no version, or one that is no UID, is no
     * publish. A key that only starts with the UID of an existing delete
     * placeholder must not be taken for it.
     *
     * @param array<string, int|string> $command The value of the version command
     */
    #[Test]
    #[DataProvider('versionCommandsWithoutAUsableVersion')]
    public function aVersionCommandWithoutAUsableVersionCollectsNothing(array $command): void
    {
        $this->insertPageVersion(7, 3, 2, 2);

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);

        $this->publishVersion('pages', 3, $command);

        self::assertSame([], $deleteSpy->events);
        self::assertSame([], $refusedSpy->events);
    }

    /**
     * Provides version commands that name no usable version.
     *
     * @return iterable<string, array{array<string, int|string>}>
     */
    public static function versionCommandsWithoutAUsableVersion(): iterable
    {
        yield 'no version named' => [['action' => 'swap']];
        yield 'a version that is no number' => [['action' => 'swap', 'swapWith' => '7x']];
        yield 'a version that does not exist' => [['action' => 'swap', 'swapWith' => 99]];
    }

    /**
     * A version record that belongs to another live record is not the version of
     * the record the command names, so its delete placeholder state does not
     * delete this record.
     */
    #[Test]
    public function aVersionOfAnotherLiveRecordIsNotTakenForTheVersionOfTheRecord(): void
    {
        // The delete placeholder 7 belongs to page 6, and the command names page 3
        $this->insertPageVersion(7, 6, 2, 2);

        $deleteSpy = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);

        $this->publishVersion('pages', 3, ['action' => 'swap', 'swapWith' => 7]);

        self::assertSame([], $deleteSpy->events);
    }

    /**
     * A version command for a live record that does not exist collects nothing.
     */
    #[Test]
    public function aVersionCommandForALiveRecordThatDoesNotExistCollectsNothing(): void
    {
        // The delete placeholder points at a page that is not there
        $this->insertPageVersion(7, 999, 2, 2);

        $deleteSpy = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);

        $this->publishVersion('pages', 999, ['action' => 'swap', 'swapWith' => 7]);

        self::assertSame([], $deleteSpy->events);
    }

    /**
     * A table without workspace support has no versions, so a version command for
     * it is left alone instead of reading columns the table does not have.
     */
    #[Test]
    public function aVersionCommandForATableWithoutWorkspaceSupportCollectsNothing(): void
    {
        $table = 'tx_typo3searchalgolia_domain_model_queueitem';

        $connection = $this->getConnectionPool()->getConnectionForTable($table);

        $connection->insert($table, ['uid' => 900, 'pid' => 0, 'table_name' => 'pages', 'record_uid' => 2, 'service_uid' => 1]);
        $connection->insert($table, ['uid' => 901, 'pid' => 0, 'table_name' => 'pages', 'record_uid' => 3, 'service_uid' => 1]);

        $deleteSpy = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);

        $this->publishVersion($table, 900, ['action' => 'swap', 'swapWith' => 901]);

        self::assertSame([], $deleteSpy->events);
    }

    /**
     * The publish of a move pointer that gives the live page the parent it has
     * already is announced with that parent as target and as former parent, like
     * a move that places a page after a sibling, so the listener can tell that
     * the page did not change its place in the tree.
     */
    #[Test]
    public function aPublishedWorkspaceMoveToTheSameParentIsAnnouncedWithTheSameParent(): void
    {
        // Page 3 lives below page 2, and so does its move pointer
        $this->insertPageVersion(7, 3, 4, 2);

        $spy = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        $this->publishVersion('pages', 3, ['action' => 'swap', 'swapWith' => 7]);

        self::assertCount(1, $spy->events);

        /** @var DataHandlerRecordMoveEvent $event */
        $event = $spy->events[0];

        self::assertSame(2, $event->getTargetPid());
        self::assertSame(2, $event->getPreviousPid());
    }

    /**
     * The publish of a move pointer for a live page that is gone afterwards
     * announces no move.
     */
    #[Test]
    public function aPublishedWorkspaceMoveOfARecordThatIsGoneIsNotAnnounced(): void
    {
        $this->insertPageVersion(7, 3, 4, 4);

        $spy = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);

        $this->publishVersion(
            'pages',
            3,
            ['action' => 'swap', 'swapWith' => 7],
            function (): void {
                $this->getConnectionPool()
                    ->getConnectionForTable('pages')
                    ->update('pages', ['deleted' => 1], ['uid' => 3]);
            }
        );

        self::assertSame([], $spy->events);
    }

    /**
     * The published move is announced once. A later post-process call of a
     * version command for the same record finds nothing that is left to announce.
     */
    #[Test]
    public function aPublishedWorkspaceMoveIsAnnouncedOnce(): void
    {
        $this->insertPageVersion(7, 3, 4, 4);

        $spy         = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $command     = ['action' => 'swap', 'swapWith' => 7];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], []);

        $GLOBALS['BE_USER']->workspace = 1;

        $hook->processCmdmap_preProcess('version', 'pages', 3, $command, $dataHandler);

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['pid' => 4], ['uid' => 3]);

        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);
        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertCount(1, $spy->events);
    }

    /**
     * The key of a command is a record UID, so the smallest UID is a record like
     * any other, while zero and negative keys are no record.
     */
    #[Test]
    public function aCommandForTheSmallestUidIsCollectedAndOneForANonPositiveKeyIsNot(): void
    {
        $deleteSpy   = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $hook        = $this->get(DataHandlerHook::class);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], []);

        $hook->processCmdmap_preProcess('delete', 'pages', 0, '', $dataHandler);
        $hook->processCmdmap_preProcess('delete', 'pages', -2, '', $dataHandler);

        self::assertSame([], $deleteSpy->events, 'No record for a key below one');

        $hook->processCmdmap_preProcess('delete', 'pages', 1, '', $dataHandler);

        self::assertCount(1, $deleteSpy->events, 'The record with the UID one');
    }

    /**
     * A publish of a workspace delete is collected and announced while the user
     * that runs the command works in the live workspace, for example a command
     * line or scheduler run.
     */
    #[Test]
    public function aPublishedWorkspaceDeleteIsAnnouncedWhileTheUserWorksInTheLiveWorkspace(): void
    {
        $this->insertPageVersion(7, 3, 2, 2);

        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->truncate('tx_typo3searchalgolia_domain_model_indexingservice');

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $GLOBALS['BE_USER']->workspace = 0;

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $command = ['action' => 'swap', 'swapWith' => 7];

        $hook->processCmdmap_preProcess('version', 'pages', 3, $command, $dataHandler);

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 3]);

        $hook->processCmdmap_postProcess('version', 'pages', 3, $command, $dataHandler);

        self::assertCount(1, $deleteSpy->events);
        self::assertCount(1, $deletedSpy->events);
    }

    /**
     * What a command collected is consumed by its post-process call. A second
     * post-process call for the same record, without a new pre-process call,
     * finds nothing that was live before it.
     */
    #[Test]
    public function aCollectedDeleteIsAnnouncedAsDeletedOnlyOnce(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->truncate('tx_typo3searchalgolia_domain_model_indexingservice');

        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);
        $refusedSpy = $this->registerEventSpy(DataHandlerRecordDeleteRefusedEvent::class);
        $hook       = $this->get(DataHandlerHook::class);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $hook->processCmdmap_preProcess('delete', 'pages', 3, '', $dataHandler);

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 3]);

        $hook->processCmdmap_postProcess('delete', 'pages', 3, '', $dataHandler);
        $hook->processCmdmap_postProcess('delete', 'pages', 3, '', $dataHandler);

        self::assertCount(1, $deletedSpy->events);
        self::assertCount(1, $refusedSpy->events, 'The second call finds nothing that was live');
    }

    /**
     * The move that DataHandler carried out is announced once, and the undelete
     * of a record is announced once, however often the post-process is called.
     */
    #[Test]
    public function aMoveAndAnUndeleteAreAnnouncedOnlyOnce(): void
    {
        $moveSpy   = $this->registerEventSpy(DataHandlerRecordMoveEvent::class);
        $updateSpy = $this->registerEventSpy(DataHandlerRecordUpdateEvent::class);
        $hook      = $this->get(DataHandlerHook::class);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        $recordWasMoved = false;
        $hook->moveRecord('pages', 3, 4, [], ['pid' => 2], 4, $recordWasMoved, $dataHandler);
        $hook->processCmdmap_postProcess('move', 'pages', 3, '4', $dataHandler);
        $hook->processCmdmap_postProcess('move', 'pages', 3, '4', $dataHandler);

        self::assertCount(1, $moveSpy->events);

        $connection = $this->getConnectionPool()->getConnectionForTable('pages');

        $connection->update('pages', ['deleted' => 1], ['uid' => 3]);

        $hook->processCmdmap_preProcess('undelete', 'pages', 3, '', $dataHandler);

        $connection->update('pages', ['deleted' => 0], ['uid' => 3]);

        $hook->processCmdmap_postProcess('undelete', 'pages', 3, '', $dataHandler);
        $hook->processCmdmap_postProcess('undelete', 'pages', 3, '', $dataHandler);

        self::assertCount(1, $updateSpy->events);
    }

    /**
     * Runs a DataHandler that acts for the given user, whatever the global one is.
     *
     * @param BackendUserAuthentication                                   $backendUser The user the DataHandler runs for
     * @param array<string, array<int|string, array<string, int|string>>> $dataMap     The records to save
     * @param array<string, array<int, array<string, int|string>>>        $cmdMap      The commands to execute
     *
     * @return void
     */
    private function runForUser(
        BackendUserAuthentication $backendUser,
        array $dataMap,
        array $cmdMap,
    ): void {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, $cmdMap, $backendUser);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
    }
}
