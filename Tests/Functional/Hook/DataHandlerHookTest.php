<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Hook;

use MeineKrankenkasse\Typo3SearchAlgolia\Hook\DataHandlerHook;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_map;
use function intval;

/**
 * Functional tests for the category handling of DataHandlerHook, run through
 * the real DataHandler, so they depend on the order in which DataHandler
 * writes category assignments and calls the hooks.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DataHandlerHook::class)]
final class DataHandlerHookTest extends AbstractFunctionalTestCase
{
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
     * @param array<string, array<int|string, array<string, int|string>>> $dataMap The records to save
     * @param array<string, array<int, array<string, int|string>>>        $cmdMap  The commands to execute
     *
     * @return DataHandler The DataHandler after processing
     */
    private function runDataHandler(
        array $dataMap,
        array $cmdMap = [],
    ): DataHandler {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, $cmdMap);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();

        self::assertSame([], $dataHandler->errorLog);

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
}
