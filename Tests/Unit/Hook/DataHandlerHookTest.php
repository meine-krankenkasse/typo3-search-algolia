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
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Unit tests for the category-related parts of DataHandlerHook.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DataHandlerHook::class)]
#[UsesClass(DataHandlerRecordDeleteEvent::class)]
#[UsesClass(PageRepository::class)]
final class DataHandlerHookTest extends TestCase
{
    /**
     * The requeuer the hook delegates the category handling to.
     */
    private CategoryRecordRequeuerInterface&MockObject $requeuerMock;

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

        $this->requeuerMock = $this->createMock(CategoryRecordRequeuerInterface::class);

        $this->subject = new DataHandlerHook(
            self::createStub(EventDispatcherInterface::class),
            new PageRepository(self::createStub(ConnectionPool::class)),
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
        unset($GLOBALS['BE_USER']);

        parent::tearDown();
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

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            $id,
            self::createStub(DataHandler::class),
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

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            $table,
            $id,
            self::createStub(DataHandler::class),
        );
    }

    /**
     * Saving a draft in a workspace does not touch the live index.
     */
    #[Test]
    public function remembersNothingInAWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace = 1;

        $this->requeuerMock->expects(self::never())->method('rememberAssignedRecords');

        $fieldArray = ['title' => 'Service'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'sys_category',
            39,
            self::createStub(DataHandler::class),
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
        $GLOBALS['BE_USER']->workspace = 1;

        $dataHandler                        = self::createStub(DataHandler::class);
        $dataHandler->substNEWwithIDs       = ['NEW1' => 40];
        $dataHandler->substNEWwithIDs_table = ['NEW1' => 'sys_category'];

        $this->requeuerMock->expects(self::never())->method('requeue');

        $this->subject->processDatamap_afterAllOperations($dataHandler);
    }
}
