<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Hook;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\CategoryRecordRequeuerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AbstractDataHandlerRecordEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteRefusedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Hook\DataHandlerHook;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepositoryInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Authentication\AccessCheckResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\VirtualRecord;

/**
 * Unit tests for the category-related parts of DataHandlerHook.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DataHandlerHook::class)]
#[UsesClass(AbstractDataHandlerRecordEvent::class)]
#[UsesClass(DataHandlerRecordDeletedEvent::class)]
#[UsesClass(DataHandlerRecordDeleteEvent::class)]
#[UsesClass(DataHandlerRecordDeleteRefusedEvent::class)]
#[UsesClass(DataHandlerRecordUpdateEvent::class)]
final class DataHandlerHookTest extends TestCase
{
    /**
     * The requeuer the hook delegates the category handling to.
     */
    private CategoryRecordRequeuerInterface&MockObject $requeuerMock;

    /**
     * The backend user of the test, working in the live workspace.
     */
    private BackendUserAuthentication&Stub $backendUserStub;

    /**
     * The page repository the hook reads the records from.
     */
    private PageRepositoryInterface&Stub $pageRepositoryStub;

    /**
     * The class, table and UID of every event that the hook of a test dispatched.
     *
     * @var list<array{class-string, string, int}>
     */
    private array $dispatchedEvents = [];

    /**
     * The records that exist and are not deleted, by table and UID.
     *
     * @var array<string, array<int, true>>
     */
    private array $liveRecords = [];

    /**
     * The records that are marked as deleted, by table and UID.
     *
     * @var array<string, array<int, true>>
     */
    private array $deletedRecords = [];

    /**
     * The hook under test.
     */
    private DataHandlerHook $subject;

    /**
     * Creates the hook and a backend user working in the live workspace.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $backendUser            = self::createStub(BackendUserAuthentication::class);
        $backendUser->workspace = 0;
        $GLOBALS['BE_USER']     = $backendUser;

        $this->backendUserStub    = $backendUser;
        $this->pageRepositoryStub = self::createStub(PageRepositoryInterface::class);

        // The live edit rules of the workspace of the user, as the real backend user applies them
        $backendUser
            ->method('workspaceAllowsLiveEditingInTable')
            ->willReturnCallback(
                static fn (string $table): bool => ($GLOBALS['BE_USER']->workspace === 0)
                    || (bool) ($GLOBALS['TCA'][$table]['ctrl']['versioningWS_alwaysAllowLiveEdit'] ?? false),
            );

        $this->requeuerMock = $this->createMock(CategoryRecordRequeuerInterface::class);

        $this->subject = new DataHandlerHook(
            self::createStub(EventDispatcherInterface::class),
            $this->pageRepositoryStub,
            self::createStub(TcaSchemaFactory::class),
            $this->requeuerMock,
        );
    }

    /**
     * Removes the backend user set up for the test.
     *
     * @return void
     */
    #[Override]
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['BE_USER'],
            $GLOBALS['TCA'],
        );

        parent::tearDown();
    }

    /**
     * Sets up the records and the permissions so that the user may update the category 39.
     *
     * @param DataHandler&Stub $dataHandler The DataHandler of the save
     *
     * @return void
     */
    private function grantUpdateOfCategory(DataHandler&Stub $dataHandler): void
    {
        $this->pageRepositoryStub
            ->method('getPageRecord')
            ->willReturnCallback(
                static fn (string $table, int $uid): array => ['uid' => $uid, 'pid' => ($table === 'pages') ? 0 : 1],
            );

        $dataHandler->method('hasPermissionToUpdate')->willReturn(true);

        $this->backendUserStub
            ->method('checkRecordEditAccess')
            ->willReturn(new AccessCheckResult(true));
    }

    /**
     * Before an existing category is saved, its assigned records are
     * remembered, whether its UID is passed as int or as a numeric string.
     *
     * @return array<string, array{int|string}> The category ids, keyed by case name
     */
    public static function existingCategoryIdProvider(): array
    {
        return [
            'integer id'        => [39],
            'numeric string id' => ['39'],
        ];
    }

    /**
     * Before an existing category is saved, its assigned records are remembered.
     *
     * @param int|string $id The record id as passed by DataHandler
     */
    #[Test]
    #[DataProvider('existingCategoryIdProvider')]
    public function remembersTheAssignedRecordsBeforeAnExistingCategoryIsSaved(int|string $id): void
    {
        $this->requeuerMock
            ->expects(self::once())
            ->method('rememberAssignedRecords')
            ->with(39);

        $dataHandler = self::createStub(DataHandler::class);
        $this->grantUpdateOfCategory($dataHandler);

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            $id,
            $dataHandler,
        );
    }

    /**
     * A category that the user may not edit is not looked at, because
     * DataHandler refuses the save and the lookup would be wasted work that the
     * size of the datamap alone drives.
     */
    #[Test]
    public function remembersNothingForACategoryTheUserMayNotEdit(): void
    {
        $this->pageRepositoryStub
            ->method('getPageRecord')
            ->willReturnCallback(
                static fn (string $table, int $uid): array => ['uid' => $uid, 'pid' => ($table === 'pages') ? 0 : 1],
            );

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler
            ->expects(self::once())
            ->method('hasPermissionToUpdate')
            ->with('sys_category', ['uid' => 1, 'pid' => 0])
            ->willReturn(false);

        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            39,
            $dataHandler,
        );
    }

    /**
     * A category stored at the root has no parent page, so the permission to update it is
     * checked against the root page.
     */
    #[Test]
    public function aCategoryAtTheRootIsCheckedAgainstTheRootPage(): void
    {
        $this->pageRepositoryStub
            ->method('getPageRecord')
            ->willReturn(['uid' => 39, 'pid' => 0]);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler
            ->expects(self::once())
            ->method('hasPermissionToUpdate')
            ->with('sys_category', VirtualRecord::RootPage)
            ->willReturn(false);

        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            39,
            $dataHandler,
        );
    }

    /**
     * A category whose record the user has no access to is not looked at,
     * although the permission on its page is given.
     */
    #[Test]
    public function remembersNothingForACategoryTheRecordAccessRefuses(): void
    {
        $this->pageRepositoryStub
            ->method('getPageRecord')
            ->willReturnCallback(
                static fn (string $table, int $uid): array => ['uid' => $uid, 'pid' => 1],
            );

        $dataHandler = self::createStub(DataHandler::class);
        $dataHandler->method('hasPermissionToUpdate')->willReturn(true);

        $this->backendUserStub
            ->method('checkRecordEditAccess')
            ->willReturn(new AccessCheckResult(false, 'No access'));

        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            39,
            $dataHandler,
        );
    }

    /**
     * A category that does not exist has nothing to remember.
     */
    #[Test]
    public function remembersNothingForACategoryThatDoesNotExist(): void
    {
        $this->pageRepositoryStub
            ->method('getPageRecord')
            ->willReturn([]);

        $dataHandler = self::createStub(DataHandler::class);
        $dataHandler->method('hasPermissionToUpdate')->willReturn(true);

        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            39,
            $dataHandler,
        );
    }

    /**
     * New categories and other tables have nothing to remember.
     *
     * @return array<string, array{string, int|string}> The table and record id, keyed by case name
     */
    public static function nothingToRememberProvider(): array
    {
        return [
            'new category' => ['sys_category', 'NEW1234'],
            'other table'  => ['pages', 39],
        ];
    }

    /**
     * New categories and other tables have nothing to remember.
     *
     * @param string     $table The table of the saved record
     * @param int|string $id    The record id as passed by DataHandler
     */
    #[Test]
    #[DataProvider('nothingToRememberProvider')]
    public function remembersNothingForNewCategoriesOrOtherTables(
        string $table,
        int|string $id,
    ): void {
        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        // DataHandler accepts the save, so only the guards of the hook decide
        $dataHandler = self::createStub(DataHandler::class);
        $this->grantUpdateOfCategory($dataHandler);

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            $table,
            $id,
            $dataHandler,
        );
    }

    /**
     * Saving a draft in a workspace does not touch the live index.
     */
    #[Test]
    public function remembersNothingInAWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace                          = 1;
        $GLOBALS['TCA']['sys_category']['ctrl']['versioningWS'] = true;

        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        // DataHandler accepts the save, so only the guards of the hook decide
        $dataHandler = self::createStub(DataHandler::class);
        $this->grantUpdateOfCategory($dataHandler);

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            39,
            $dataHandler,
        );
    }

    /**
     * A workspace user changes the live records of a table that allows the live
     * edit in every workspace, so its assignments are remembered.
     */
    #[Test]
    public function remembersTheAssignedRecordsOfATableThatAlwaysAllowsTheLiveEdit(): void
    {
        $GLOBALS['BE_USER']->workspace                                              = 1;
        $GLOBALS['TCA']['sys_category']['ctrl']['versioningWS']                     = true;
        $GLOBALS['TCA']['sys_category']['ctrl']['versioningWS_alwaysAllowLiveEdit'] = true;

        $this->requeuerMock
            ->expects(self::once())
            ->method('rememberAssignedRecords')
            ->with(39);

        $dataHandler = self::createStub(DataHandler::class);
        $this->grantUpdateOfCategory($dataHandler);

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            39,
            $dataHandler,
        );
    }

    /**
     * New categories are requeued once all operations are done, because
     * DataHandler only writes their assignments after the per-record hooks ran.
     */
    #[Test]
    public function requeuesNewCategoriesAfterAllOperations(): void
    {
        $dataHandler                  = self::createStub(DataHandler::class);
        $dataHandler->substNEWwithIDs = [
            'NEW1' => 40,
            'NEW2' => 41,
            'NEW3' => 12,
        ];
        $dataHandler->substNEWwithIDs_table = [
            'NEW1' => 'sys_category',
            'NEW2' => 'pages',
            'NEW3' => 'sys_category',
        ];

        $requeuedCategoryUids = [];

        $this->requeuerMock
            ->expects(self::exactly(2))
            ->method('requeue')
            ->willReturnCallback(
                static function (int $categoryUid) use (&$requeuedCategoryUids): void {
                    $requeuedCategoryUids[] = $categoryUid;
                },
            );

        $this->subject->processDatamap_afterAllOperations($dataHandler);

        self::assertSame([40, 12], $requeuedCategoryUids);
    }

    /**
     * Before a category is deleted nothing is remembered or requeued yet.
     * Requeueing waits until DataHandler has actually deleted it, which it
     * may still refuse at this point.
     */
    #[Test]
    public function touchesNoCategoryRecordsBeforeACategoryIsDeleted(): void
    {
        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');
        $this->requeuerMock->expects(self::never())->method('requeue');

        $this->subject->processCmdmap_preProcess(
            'delete',
            'sys_category',
            39,
            '',
            self::createStub(DataHandler::class),
        );
    }

    /**
     * The records of a table without workspace support have no version fields, so a move
     * command for one of them looks for no live record of a version.
     */
    #[Test]
    public function aMoveOfARecordOfATableWithoutWorkspaceSupportLooksForNoVersionOfIt(): void
    {
        $requestedFields = [];

        $pageRepository = self::createStub(PageRepositoryInterface::class);
        $pageRepository
            ->method('getPageRecord')
            ->willReturnCallback(
                static function (string $table, int $uid, string $fields = '*') use (&$requestedFields): array {
                    $requestedFields[] = $fields;

                    return [];
                },
            );

        $subject = new DataHandlerHook(
            self::createStub(EventDispatcherInterface::class),
            $pageRepository,
            self::createStub(TcaSchemaFactory::class),
            $this->requeuerMock,
        );

        $this->requeuerMock->expects(self::never())->method('requeue');

        $subject->processCmdmap_postProcess(
            'move',
            'be_groups',
            900,
            '0',
            self::createStub(DataHandler::class),
        );

        self::assertNotContains('t3ver_oid', $requestedFields);
    }

    /**
     * Once all operations are done, the records remembered for the datamap's
     * existing categories are dropped. A save DataHandler refused never
     * reaches the requeue, so its snapshot would otherwise stay behind.
     */
    #[Test]
    public function forgetsTheRememberedRecordsOfTheSavedCategoriesAfterAllOperations(): void
    {
        $dataHandler          = self::createStub(DataHandler::class);
        $dataHandler->datamap = [
            'sys_category' => [
                39     => ['title' => 'Service'],
                'NEW1' => ['title' => 'New'],
            ],
            'pages' => [
                41 => ['title' => 'Page'],
            ],
        ];

        $this->requeuerMock
            ->expects(self::once())
            ->method('forgetRememberedRecords')
            ->with(39);

        $this->subject->processDatamap_afterAllOperations($dataHandler);
    }

    /**
     * New categories created as workspace drafts are not requeued.
     */
    #[Test]
    public function requeuesNoNewCategoriesInAWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace                          = 1;
        $GLOBALS['TCA']['sys_category']['ctrl']['versioningWS'] = true;

        $dataHandler                        = self::createStub(DataHandler::class);
        $dataHandler->substNEWwithIDs       = ['NEW1' => 40];
        $dataHandler->substNEWwithIDs_table = ['NEW1' => 'sys_category'];

        $this->requeuerMock->expects(self::never())->method('requeue');

        $this->subject->processDatamap_afterAllOperations($dataHandler);
    }

    /**
     * Creates a hook that records the events it dispatches and reads the state of the records
     * from the properties of the test at the time of every lookup.
     *
     * @return DataHandlerHook The hook under test
     */
    private function createCollectingHook(): DataHandlerHook
    {
        $this->pageRepositoryStub
            ->method('hasPageRecord')
            ->willReturnCallback(
                fn (string $table, int $uid, bool $useDeleteClause = true): bool => isset($this->liveRecords[$table][$uid])
                    || (!$useDeleteClause && isset($this->deletedRecords[$table][$uid])),
            );

        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $dispatcher
            ->method('dispatch')
            ->willReturnCallback(
                function (object $event): object {
                    if (
                        ($event instanceof AbstractDataHandlerRecordEvent)
                        || ($event instanceof DataHandlerRecordUpdateEvent)
                    ) {
                        $this->dispatchedEvents[] = [$event::class, $event->getTable(), $event->getRecordUid()];
                    }

                    return $event;
                },
            );

        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        return new DataHandlerHook(
            $dispatcher,
            $this->pageRepositoryStub,
            self::createStub(TcaSchemaFactory::class),
            $this->requeuerMock,
        );
    }

    /**
     * A delete of a live record is announced before the command and, once DataHandler
     * removed the record, as deleted.
     */
    #[Test]
    public function aDeleteOfALiveRecordIsAnnouncedAsDeletedOnceDataHandlerRemovedIt(): void
    {
        $this->liveRecords['tt_content'][5] = true;

        $hook        = $this->createCollectingHook();
        $dataHandler = self::createStub(DataHandler::class);

        $hook->processCmdmap_preProcess('delete', 'tt_content', 5, '', $dataHandler);

        self::assertSame([[DataHandlerRecordDeleteEvent::class, 'tt_content', 5]], $this->dispatchedEvents);

        unset($this->liveRecords['tt_content'][5]);

        $hook->processCmdmap_postProcess('delete', 'tt_content', 5, '', $dataHandler);

        self::assertSame(
            [
                [DataHandlerRecordDeleteEvent::class, 'tt_content', 5],
                [DataHandlerRecordDeletedEvent::class, 'tt_content', 5],
            ],
            $this->dispatchedEvents,
        );
    }

    /**
     * A delete that leaves the record live is announced as refused, so that what was
     * collected for it is discarded.
     */
    #[Test]
    public function aDeleteThatLeavesTheRecordIsAnnouncedAsRefused(): void
    {
        $this->liveRecords['tt_content'][5] = true;

        $hook        = $this->createCollectingHook();
        $dataHandler = self::createStub(DataHandler::class);

        $hook->processCmdmap_preProcess('delete', 'tt_content', 5, '', $dataHandler);
        $hook->processCmdmap_postProcess('delete', 'tt_content', 5, '', $dataHandler);

        self::assertSame(
            [
                [DataHandlerRecordDeleteEvent::class, 'tt_content', 5],
                [DataHandlerRecordDeleteRefusedEvent::class, 'tt_content', 5],
            ],
            $this->dispatchedEvents,
        );
    }

    /**
     * A delete of a record that was no live record before the command is no delete of a
     * document, so it is announced as refused.
     */
    #[Test]
    public function aDeleteOfARecordThatWasNotLiveIsAnnouncedAsRefused(): void
    {
        $hook        = $this->createCollectingHook();
        $dataHandler = self::createStub(DataHandler::class);

        $hook->processCmdmap_preProcess('delete', 'tt_content', 5, '', $dataHandler);
        $hook->processCmdmap_postProcess('delete', 'tt_content', 5, '', $dataHandler);

        self::assertSame(
            [
                [DataHandlerRecordDeleteEvent::class, 'tt_content', 5],
                [DataHandlerRecordDeleteRefusedEvent::class, 'tt_content', 5],
            ],
            $this->dispatchedEvents,
        );
    }

    /**
     * A delete command of a user whose workspace cannot change the live record announces nothing.
     */
    #[Test]
    public function aDeleteInAWorkspaceThatCannotChangeTheLiveRecordIsNotAnnounced(): void
    {
        $GLOBALS['BE_USER']->workspace                        = 1;
        $GLOBALS['TCA']['tt_content']['ctrl']['versioningWS'] = true;

        $this->liveRecords['tt_content'][5] = true;

        $hook        = $this->createCollectingHook();
        $dataHandler = self::createStub(DataHandler::class);

        $hook->processCmdmap_preProcess('delete', 'tt_content', 5, '', $dataHandler);
        $hook->processCmdmap_postProcess('delete', 'tt_content', 5, '', $dataHandler);

        self::assertSame([], $this->dispatchedEvents);
    }

    /**
     * A DataHandler that runs for another user than the global one decides by that user.
     */
    #[Test]
    public function aDataHandlerOfAnotherUserDecidesByThatUser(): void
    {
        $otherUser            = self::createStub(BackendUserAuthentication::class);
        $otherUser->workspace = 1;
        $otherUser->method('workspaceAllowsLiveEditingInTable')->willReturn(false);

        $dataHandler          = self::createStub(DataHandler::class);
        $dataHandler->BE_USER = $otherUser;

        $this->liveRecords['tt_content'][5] = true;

        $hook = $this->createCollectingHook();

        $hook->processCmdmap_preProcess('delete', 'tt_content', 5, '', $dataHandler);
        $hook->processCmdmap_postProcess('delete', 'tt_content', 5, '', $dataHandler);

        self::assertSame([], $this->dispatchedEvents);
    }

    /**
     * Provides keys of a delete command and the events that the command announces.
     *
     * @return iterable<string, array{int|string, list<array{class-string, string, int}>}>
     */
    public static function deleteCommandKeys(): iterable
    {
        yield 'key zero reads as no record' => [0, []];
        yield 'text reads as no record' => ['abc', []];
        yield 'record one is a record' => [
            1,
            [
                [DataHandlerRecordDeleteEvent::class, 'tt_content', 1],
                [DataHandlerRecordDeleteRefusedEvent::class, 'tt_content', 1],
            ],
        ];
    }

    /**
     * A key that reads as no record is no command for a record, while the first UID is one.
     *
     * @param int|string                             $key      The key of the delete command
     * @param list<array{class-string, string, int}> $expected The events that the command announces
     */
    #[Test]
    #[DataProvider('deleteCommandKeys')]
    public function aDeleteCommandIsAnnouncedOnlyForAKeyThatReadsAsARecord(int|string $key, array $expected): void
    {
        $hook        = $this->createCollectingHook();
        $dataHandler = self::createStub(DataHandler::class);

        $hook->processCmdmap_preProcess('delete', 'tt_content', $key, '', $dataHandler);
        $hook->processCmdmap_postProcess('delete', 'tt_content', $key, '', $dataHandler);

        self::assertSame($expected, $this->dispatchedEvents);
    }

    /**
     * Provides the state of a record before and after a command, and the events of the command.
     *
     * @return iterable<string, array{string, bool, bool, list<array{class-string, string, int}>}>
     */
    public static function undeleteStates(): iterable
    {
        $update = [[DataHandlerRecordUpdateEvent::class, 'tt_content', 7]];

        yield 'deleted record that is live afterwards' => ['undelete', true, true, $update];
        yield 'record that was live already' => ['undelete', false, true, []];
        yield 'deleted record that stays deleted' => ['undelete', true, false, []];
        yield 'another command' => ['copy', true, true, []];
    }

    /**
     * An undelete is announced as an update only for a record that was deleted before the
     * command and is live afterwards.
     *
     * @param string                                 $command       The DataHandler command
     * @param bool                                   $deletedBefore Whether the record is marked as deleted before the command
     * @param bool                                   $liveAfter     Whether the record is live after the command
     * @param list<array{class-string, string, int}> $expected      The events that the command announces
     */
    #[Test]
    #[DataProvider('undeleteStates')]
    public function anUndeleteIsAnnouncedAsAnUpdateOnlyForARecordThatCameBack(
        string $command,
        bool $deletedBefore,
        bool $liveAfter,
        array $expected,
    ): void {
        if ($deletedBefore) {
            $this->deletedRecords['tt_content'][7] = true;
        } else {
            $this->liveRecords['tt_content'][7] = true;
        }

        $hook        = $this->createCollectingHook();
        $dataHandler = self::createStub(DataHandler::class);

        $hook->processCmdmap_preProcess($command, 'tt_content', 7, '', $dataHandler);

        unset($this->deletedRecords['tt_content'][7], $this->liveRecords['tt_content'][7]);

        if ($liveAfter) {
            $this->liveRecords['tt_content'][7] = true;
        } else {
            $this->deletedRecords['tt_content'][7] = true;
        }

        $hook->processCmdmap_postProcess($command, 'tt_content', 7, '', $dataHandler);

        self::assertSame($expected, $this->dispatchedEvents);
    }

    /**
     * Provides the command, the table and whether the record is live after the command,
     * and whether the category is requeued.
     *
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function categoryDeletes(): iterable
    {
        yield 'category that is gone' => ['delete', 'sys_category', false, true];
        yield 'category that is still live' => ['delete', 'sys_category', true, false];
        yield 'record of another table that is gone' => ['delete', 'tt_content', false, false];
        yield 'another command on a category that is gone' => ['move', 'sys_category', false, false];
    }

    /**
     * The records of a category are requeued once the category really is deleted.
     *
     * @param string $command    The DataHandler command
     * @param string $table      The table of the record
     * @param bool   $liveAfter  Whether the record is live after the command
     * @param bool   $isRequeued Whether the category is requeued
     */
    #[Test]
    #[DataProvider('categoryDeletes')]
    public function aCategoryIsRequeuedOnlyOnceItIsReallyDeleted(
        string $command,
        string $table,
        bool $liveAfter,
        bool $isRequeued,
    ): void {
        $this->liveRecords[$table][39] = true;

        $hook        = $this->createCollectingHook();
        $dataHandler = self::createStub(DataHandler::class);

        $this->requeuerMock
            ->expects($isRequeued ? self::once() : self::never())
            ->method('requeue')
            ->with(39);

        $hook->processCmdmap_preProcess($command, $table, 39, '', $dataHandler);

        if (!$liveAfter) {
            unset($this->liveRecords[$table][39]);
        }

        $hook->processCmdmap_postProcess($command, $table, 39, '', $dataHandler);
    }
}
