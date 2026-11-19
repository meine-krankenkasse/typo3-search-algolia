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
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
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
#[UsesClass(DataHandlerRecordDeleteEvent::class)]
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
}
