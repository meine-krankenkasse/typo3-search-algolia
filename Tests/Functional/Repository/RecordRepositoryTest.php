<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AddsInlineChildRelationTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function count;
use function range;
use function sort;

/**
 * Functional tests for RecordRepository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordRepository::class)]
final class RecordRepositoryTest extends AbstractFunctionalTestCase
{
    use AddsInlineChildRelationTrait;

    private RecordRepository $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content.csv');

        $this->subject = new RecordRepository($this->getConnectionPool());
    }

    /**
     * Tests that findPid() returns the page ID (pid) for an existing
     * page record in the database.
     */
    #[Test]
    public function findPidReturnsPidForExistingPage(): void
    {
        $pid = $this->subject->findPid('pages', 2);

        self::assertSame(1, $pid);
    }

    /**
     * Tests that findPid() returns the page ID (pid) for an existing
     * content element record in the tt_content table.
     */
    #[Test]
    public function findPidReturnsPidForContentElement(): void
    {
        $pid = $this->subject->findPid('tt_content', 1);

        self::assertSame(2, $pid);
    }

    /**
     * Tests that findPid() returns false when the specified record
     * does not exist in the database.
     */
    #[Test]
    public function findPidReturnsFalseForNonExistentRecord(): void
    {
        $pid = $this->subject->findPid('pages', 99999);

        self::assertFalse($pid);
    }

    /**
     * Tests that findPid() returns zero for the root page, since
     * the root page has pid=0 by convention.
     */
    #[Test]
    public function findPidReturnsZeroPidForRootPage(): void
    {
        $pid = $this->subject->findPid('pages', 1);

        self::assertSame(0, $pid);
    }

    /**
     * Tests that findTranslationUids() returns the translations of the given
     * records, hidden ones included, and leaves out deleted translations and
     * the ones of other records.
     */
    #[Test]
    public function findTranslationUidsReturnsTheNotDeletedTranslations(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        foreach ([[80, 1, 0, 1], [81, 1, 1, 0], [82, 99, 0, 0]] as [$uid, $parent, $deleted, $hidden]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 2,
                    'header'           => 'Translation',
                    'CType'            => 'text',
                    'sys_language_uid' => 1,
                    'l18n_parent'      => $parent,
                    'deleted'          => $deleted,
                    'hidden'           => $hidden,
                ]
            );
        }

        self::assertSame([80], $this->subject->findTranslationUids('tt_content', [1, 2]));
    }

    /**
     * Tests that findDependentUids() finds the child records of an inline
     * relation of the given records, their own children too, and leaves out
     * deleted children, tables that are not asked for and records that were seen.
     */
    #[Test]
    public function findDependentUidsFollowsTheInlineRelationsOfTheTable(): void
    {
        $this->addInlineChildRelation();

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Content element 1 has the children 90 and 91 (deleted), and 90 has the child 92
        foreach ([[90, 1, 0], [91, 1, 1], [92, 90, 0], [93, 99, 0]] as [$uid, $parent, $deleted]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'            => $uid,
                    'pid'            => 2,
                    'header'         => 'Child',
                    'CType'          => 'text',
                    'tx_test_parent' => $parent,
                    'deleted'        => $deleted,
                ]
            );
        }

        $children = $this->subject->findDependentUids('tt_content', 1, ['tt_content']);

        sort($children['tt_content']);

        self::assertSame(['tt_content' => [90, 92]], $children);
        self::assertSame(['tt_content' => []], $this->subject->findDependentUids('tt_content', 1, ['pages']), 'A table that is not asked for');
        self::assertSame(['pages' => []], $this->subject->findDependentUids('pages', 2, ['tt_content']), 'A table without an inline relation');
    }

    /**
     * Tests that findDependentUids() names the translations of a record, the children of
     * its inline relation and the translations of those children, once each.
     */
    #[Test]
    public function findDependentUidsNamesTranslationsChildrenAndTheirTranslations(): void
    {
        $this->addInlineChildRelation();

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Element 80 translates element 1, child 90 belongs to element 1, element 91 translates the child
        foreach ([[80, 0, 1], [90, 1, 0], [91, 0, 90]] as [$uid, $parent, $translationParent]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 2,
                    'header'           => 'Dependent',
                    'CType'            => 'text',
                    'tx_test_parent'   => $parent,
                    'sys_language_uid' => ($translationParent > 0) ? 1 : 0,
                    'l18n_parent'      => $translationParent,
                ]
            );
        }

        $dependents = $this->subject->findDependentUids('tt_content', 1, ['tt_content']);

        sort($dependents['tt_content']);

        self::assertSame(['tt_content' => [80, 90, 91]], $dependents);
    }

    /**
     * Tests that the lookups leave out the workspace versions of records, which
     * carry the parent of the live record and are no documents of the index.
     */
    #[Test]
    public function lookupsLeaveOutWorkspaceVersionsOfRecords(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'         => 95,
                    'pid'         => 2,
                    'header'      => 'Version',
                    'CType'       => 'text',
                    't3ver_oid'   => 1,
                    't3ver_wsid'  => 1,
                    't3ver_state' => 0,
                ]
            );

        self::assertNotContains(95, $this->subject->findUidsByPids('tt_content', [2]));
        self::assertNotContains(95, $this->subject->findExistingUids('tt_content', [1, 95]));
    }

    /**
     * Tests that findDependentUids() ends for data that is a ring, and names
     * every child once.
     */
    #[Test]
    public function findDependentUidsEndsForADataRing(): void
    {
        $this->addInlineChildRelation();

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Element 96 is its own child, elements 97 and 98 are each other's child
        foreach ([[96, 96], [97, 98], [98, 97]] as [$uid, $parent]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'            => $uid,
                    'pid'            => 2,
                    'header'         => 'Ring',
                    'CType'          => 'text',
                    'tx_test_parent' => $parent,
                ]
            );
        }

        self::assertSame(['tt_content' => [96]], $this->subject->findDependentUids('tt_content', 96, ['tt_content']));

        $ring = $this->subject->findDependentUids('tt_content', 97, ['tt_content']);

        sort($ring['tt_content']);

        self::assertSame(['tt_content' => [97, 98]], $ring);
    }

    /**
     * Tests that findDependentUids() leaves out a relation whose children name
     * the table of the parent in a field, because the UID alone does not tell
     * which parent a child belongs to, and a column that is no inline relation.
     */
    #[Test]
    public function findDependentUidsLeavesOutRelationsWithAForeignTableFieldAndOtherColumnTypes(): void
    {
        $this->addInlineChildRelation();

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'            => 90,
                    'pid'            => 2,
                    'header'         => 'Child',
                    'CType'          => 'text',
                    'tx_test_parent' => 1,
                ]
            );

        self::assertSame(['tt_content' => [90]], $this->subject->findDependentUids('tt_content', 1, ['tt_content']));

        $GLOBALS['TCA']['tt_content']['columns']['tx_test_children']['config']['foreign_table_field'] = 'tablenames';

        self::assertSame(['tt_content' => []], $this->subject->findDependentUids('tt_content', 1, ['tt_content']), 'A relation with a foreign table field');

        unset($GLOBALS['TCA']['tt_content']['columns']['tx_test_children']['config']['foreign_table_field']);

        $GLOBALS['TCA']['tt_content']['columns']['tx_test_children']['config']['type'] = 'select';

        self::assertSame(['tt_content' => []], $this->subject->findDependentUids('tt_content', 1, ['tt_content']), 'A column that is no inline relation');
    }

    /**
     * Tests that findTranslationUids() finds the translations of pages, which
     * are pages themselves, and answers nothing for a table without a
     * translation field or without records to look for.
     */
    #[Test]
    public function findTranslationUidsHandlesPagesAndTablesWithoutTranslations(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'              => 90,
                    'pid'              => 1,
                    'title'            => 'Translated Page',
                    'doktype'          => 1,
                    'sys_language_uid' => 1,
                    'l10n_parent'      => 2,
                ]
            );

        self::assertSame([90], $this->subject->findTranslationUids('pages', [2]));
        self::assertSame([], $this->subject->findTranslationUids('pages', []));
        self::assertSame([], $this->subject->findTranslationUids('table_that_does_not_exist', [2]));
    }

    /**
     * Tests that findUidsByPids() returns the records of the given pages, hidden
     * ones and ones outside of their start and end time included, and leaves
     * out deleted ones and tables that do not exist.
     */
    #[Test]
    public function findUidsByPidsReturnsTheNotDeletedRecordsOfThePages(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content_lifecycle.csv');

        $uids = $this->subject->findUidsByPids('tt_content', [2, 3]);

        sort($uids);

        // 10 is hidden, 11 is deleted, 12 starts in the future, 13 has ended
        self::assertSame([1, 2, 3, 4, 5, 10, 12, 13], $uids);
        self::assertSame([], $this->subject->findUidsByPids('table_that_does_not_exist', [2, 3]));
        self::assertSame([], $this->subject->findUidsByPids('tt_content', []));
    }

    /**
     * Tests that findUidsByPids() answers for more pages than one chunk holds.
     */
    #[Test]
    public function findUidsByPidsAnswersAcrossTheBoundaryOfAChunk(): void
    {
        // The pages 2 and 3 land in different chunks, the rest are pages that do not exist
        $pageIds = [2, ...range(1000, 1998), 3];

        self::assertGreaterThan(Constants::IN_LIST_BLOCK_SIZE, count($pageIds), 'The last page is behind the first block');

        $uids = $this->subject->findUidsByPids('tt_content', $pageIds);

        sort($uids);

        self::assertSame([1, 2, 3, 4, 5], $uids);
    }

    /**
     * Tests that findUidsByPidAndLanguage() returns the records of one language
     * on a page, and nothing for a table without a language field.
     */
    #[Test]
    public function findUidsByPidAndLanguageReturnsTheRecordsOfTheLanguage(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        foreach ([[91, 2, 1], [92, 2, 2], [93, 3, 1]] as [$uid, $pid, $language]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => $pid,
                    'header'           => 'Content',
                    'CType'            => 'text',
                    'sys_language_uid' => $language,
                ]
            );
        }

        // A hidden record, one that starts later and a deleted one on the same page and language
        foreach ([[94, 1, 0, 0], [95, 0, 1, 0], [96, 0, 0, 4102444800]] as [$uid, $hidden, $deleted, $starttime]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 2,
                    'header'           => 'Content',
                    'CType'            => 'text',
                    'sys_language_uid' => 1,
                    'hidden'           => $hidden,
                    'deleted'          => $deleted,
                    'starttime'        => $starttime,
                ]
            );
        }

        // A workspace version of a record of that page and language is no document of the index
        $connection->insert(
            'tt_content',
            [
                'uid'              => 97,
                'pid'              => 2,
                'header'           => 'Version',
                'CType'            => 'text',
                'sys_language_uid' => 1,
                't3ver_oid'        => 91,
                't3ver_wsid'       => 1,
                't3ver_state'      => 0,
            ]
        );

        $uids = $this->subject->findUidsByPidAndLanguage('tt_content', 2, 1);

        sort($uids);

        self::assertSame([91, 94, 96], $uids);
        self::assertSame([], $this->subject->findUidsByPidAndLanguage('table_that_does_not_exist', 2, 1));

        // DataHandler only deletes the records of a table that can be localized, which needs
        // the translation parent field next to the language field
        $parentField = $GLOBALS['TCA']['tt_content']['ctrl']['transOrigPointerField'];

        unset($GLOBALS['TCA']['tt_content']['ctrl']['transOrigPointerField']);

        try {
            self::assertSame([], $this->subject->findUidsByPidAndLanguage('tt_content', 2, 1));
        } finally {
            $GLOBALS['TCA']['tt_content']['ctrl']['transOrigPointerField'] = $parentField;
        }
    }

    /**
     * Tests that findUidsByPidAndLanguage() returns a record whose end time has
     * passed, because DataHandler deletes it together with the translation of
     * the page and its document has to go as well.
     */
    #[Test]
    public function findUidsByPidAndLanguageIncludesARecordWhoseEndtimeHasPassed(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        foreach ([[91, 0], [92, 1]] as [$uid, $endtime]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 2,
                    'header'           => 'Content',
                    'CType'            => 'text',
                    'sys_language_uid' => 1,
                    'endtime'          => $endtime,
                ]
            );
        }

        $uids = $this->subject->findUidsByPidAndLanguage('tt_content', 2, 1);

        sort($uids);

        self::assertSame([91, 92], $uids);
    }

    /**
     * Tests that findExistingUids() returns only the records that are not deleted.
     */
    #[Test]
    public function findExistingUidsLeavesOutDeletedRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tt_content_lifecycle.csv');

        $uids = $this->subject->findExistingUids('tt_content', [1, 10, 11, 999]);

        sort($uids);

        self::assertSame([1, 10], $uids);
        self::assertSame([], $this->subject->findExistingUids('tt_content', []));
    }

    /**
     * Tests that findUidsWhere() finds the pages that are the root of a site among the
     * given pages, hidden ones and ones outside of their time included, and answers for
     * more values than one block holds. The test database takes the whole list in one
     * statement, so this pins the answer and not the size of the block.
     */
    #[Test]
    public function findUidsWhereFindsTheRecordsThatEqualTheGivenFields(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');

        $pages->insert('pages', ['uid' => 30, 'pid' => 1, 'title' => 'A', 'doktype' => 1, 'is_siteroot' => 1, 'hidden' => 1]);
        $pages->insert('pages', ['uid' => 31, 'pid' => 1, 'title' => 'B', 'doktype' => 1, 'is_siteroot' => 1, 'endtime' => 1000]);
        $pages->insert('pages', ['uid' => 32, 'pid' => 1, 'title' => 'C', 'doktype' => 1, 'is_siteroot' => 0]);

        // Site root 30 sits behind the first block of values
        $values = [32, 31, 3, ...range(2000, 3100), 30];

        self::assertGreaterThan(Constants::IN_LIST_BLOCK_SIZE, count($values), 'The last value is behind the first block');

        $siteRootIds = $this->subject->findUidsWhere('pages', 'uid', $values, ['is_siteroot' => 1]);

        sort($siteRootIds);

        self::assertSame([30, 31], $siteRootIds);
        self::assertSame([], $this->subject->findUidsWhere('pages', 'uid', [], ['is_siteroot' => 1]));
    }
}
