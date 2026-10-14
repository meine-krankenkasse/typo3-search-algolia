<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteRefusedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record\RecordDeleteEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\RecordRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AddsInlineChildRelationTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Functional tests for RecordDeleteEventListener.
 *
 * Tests the delete flow end-to-end with real DB queries for page record
 * lookup and root page resolution. Mocks IndexerFactory and SearchEngineFactory
 * to avoid external service calls.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(RecordDeleteEventListener::class)]
final class RecordDeleteEventListenerTest extends AbstractFunctionalTestCase
{
    use AddsInlineChildRelationTrait;

    /**
     * The indexer factory that hands out the indexer mock.
     */
    private MockObject&IndexerFactory $indexerFactoryMock;

    /**
     * The search engine factory that hands out the search engine mock.
     */
    private MockObject&SearchEngineFactory $searchEngineFactoryMock;

    /**
     * The indexer that the listener asks, configured per test.
     */
    private MockObject&IndexerInterface $indexerMock;

    /**
     * The listener under test.
     */
    private RecordDeleteEventListener $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');

        $connectionPool = $this->getConnectionPool();
        $pageRepository = new PageRepository($connectionPool);

        // Create indexer mock that can be returned by the factory
        $this->indexerMock = $this->createMock(IndexerInterface::class);
        $this->indexerMock
            ->method('getTable')
            ->willReturn('pages');
        $this->indexerMock
            ->method('withIndexingService')
            ->willReturn($this->indexerMock);

        $this->indexerFactoryMock      = $this->createMock(IndexerFactory::class);
        $this->searchEngineFactoryMock = $this->createMock(SearchEngineFactory::class);

        // IndexingServiceRepository via DI uses Extbase persistence
        $indexingServiceRepository = $this->get(IndexingServiceRepository::class);

        $recordHandler = new RecordHandler(
            $this->searchEngineFactoryMock,
            $this->indexerFactoryMock,
            $pageRepository,
            $indexingServiceRepository,
            new ContentRepository($connectionPool),
        );

        $this->subject = new RecordDeleteEventListener(
            $recordHandler,
            new RecordRepository($connectionPool),
            $pageRepository,
        );
    }

    /**
     * Tests that the listener does not remove the record itself while it is
     * only about to be deleted, because DataHandler may still refuse the command.
     */
    #[Test]
    public function invokeRemovesNothingBeforeTheRecordIsDeleted(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->expects(self::never())
            ->method('dequeueOne');
        $this->indexerMock
            ->expects(self::never())
            ->method('dequeueMultiple');

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));
    }

    /**
     * Tests that the context of a record whose delete command ended without a
     * deletion is discarded, so that it is neither kept for the rest of the run
     * nor used for a deleted event that arrives for the record afterwards.
     */
    #[Test]
    public function onRefusedDiscardsTheCollectedContext(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $this->indexerMock
            ->expects(self::never())
            ->method('dequeueOne');
        $this->indexerMock
            ->expects(self::never())
            ->method('dequeueMultiple');

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));
        $this->subject->onRefused(new DataHandlerRecordDeleteRefusedEvent('pages', 2));
        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));
    }

    /**
     * Tests that discarding the context of one record leaves the context of
     * another record of the same command list alone.
     */
    #[Test]
    public function onRefusedKeepsTheContextOfAnotherRecord(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $dequeued = [];
        $this->indexerMock
            ->method('dequeueMultiple')
            ->willReturnCallback(
                function (array $uids) use (&$dequeued): IndexerInterface {
                    $dequeued = [...$dequeued, ...$uids];

                    return $this->indexerMock;
                }
            );

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));
        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 6));

        $this->subject->onRefused(new DataHandlerRecordDeleteRefusedEvent('pages', 6));
        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));
        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 6));

        self::assertContains(2, $dequeued, 'The page that was deleted is removed');
        self::assertNotContains(6, $dequeued, 'The page whose command was refused stays');
    }

    /**
     * Tests that the collected context is used once. A second deleted event for
     * the same record removes nothing, and the context is not kept after use.
     */
    #[Test]
    public function onDeletedUsesTheCollectedContextOnce(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $calls = 0;
        $this->indexerMock
            ->method('dequeueMultiple')
            ->willReturnCallback(
                function () use (&$calls): IndexerInterface {
                    ++$calls;

                    return $this->indexerMock;
                }
            );

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));
        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        $callsAfterFirst = $calls;

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertGreaterThan(0, $callsAfterFirst, 'The first deleted event removed the records');
        self::assertSame($callsAfterFirst, $calls, 'The second one removed nothing');
    }

    /**
     * Tests that the listener removes a page from the indexing queue once
     * the record was announced as deleted.
     */
    #[Test]
    public function onDeletedDeletesPageFromQueue(): void
    {
        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($this->indexerMock);

        $dequeued = [];
        $this->indexerMock
            ->method('dequeueMultiple')
            ->willReturnCallback(
                function (array $uids) use (&$dequeued): IndexerInterface {
                    $dequeued = [...$dequeued, ...$uids];

                    return $this->indexerMock;
                }
            );

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));
        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertContains(2, $dequeued, 'The deleted page is removed from the queue');
    }

    /**
     * Tests that the listener removes a content element from the indexing
     * queue once the record was announced as deleted.
     */
    #[Test]
    public function onDeletedDeletesContentElementFromQueue(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('tt_content');
        $indexerMock
            ->method('withIndexingService')
            ->willReturn($indexerMock);
        $indexerMock
            ->expects(self::atLeastOnce())
            ->method('dequeueMultiple')
            ->with([1])
            ->willReturn($indexerMock);

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($indexerMock);

        ($this->subject)(new DataHandlerRecordDeleteEvent('tt_content', 1));
        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('tt_content', 1));
    }

    /**
     * Tests that the page of a deleted content element is queued again once
     * the element is deleted, and not before, with the root page that was
     * collected while the element still existed.
     */
    #[Test]
    public function onDeletedQueuesThePageOfADeletedContentElementAgain(): void
    {
        $queued   = [];
        $dequeued = [];

        $pageIndexerMock = $this->createMock(PageIndexer::class);
        $pageIndexerMock
            ->method('getTable')
            ->willReturn('pages');
        $pageIndexerMock
            ->method('withIndexingService')
            ->willReturn($pageIndexerMock);
        $pageIndexerMock
            ->method('isIncludeContentElements')
            ->willReturn(true);
        $pageIndexerMock
            ->method('dequeueOne')
            ->willReturnCallback(
                static function (int $uid) use (&$dequeued, $pageIndexerMock): PageIndexer {
                    $dequeued[] = $uid;

                    return $pageIndexerMock;
                }
            );
        $pageIndexerMock
            ->method('enqueueOne')
            ->willReturnCallback(
                static function (int $uid) use (&$queued): int {
                    $queued[] = $uid;

                    return 1;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($pageIndexerMock);

        ($this->subject)(new DataHandlerRecordDeleteEvent('tt_content', 1));

        self::assertNotContains(2, $queued, 'The page is not queued again before the deletion');

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 1]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('tt_content', 1));

        // Content element 1 lives on page 2
        self::assertContains(2, $queued, 'The page of the deleted content element is queued again');
        self::assertContains(2, $dequeued);
    }

    /**
     * Tests that the deletion of a record of another table queues the page of
     * no content element, although a content element of the same UID exists.
     * Only a content element has a page that holds its document.
     */
    #[Test]
    public function onDeletedQueuesNoPageForARecordOfAnotherTable(): void
    {
        // Content element 1 lives on page 2, and the category has the same UID
        $this->getConnectionPool()
            ->getConnectionForTable('sys_category')
            ->insert('sys_category', ['uid' => 1, 'pid' => 1, 'title' => 'Category']);

        $pageIndexerMock = $this->createMock(PageIndexer::class);
        $pageIndexerMock->method('getTable')->willReturn('pages');
        $pageIndexerMock->method('withIndexingService')->willReturn($pageIndexerMock);
        $pageIndexerMock->method('isIncludeContentElements')->willReturn(true);
        $pageIndexerMock->expects(self::never())->method('dequeueOne');
        $pageIndexerMock->expects(self::never())->method('enqueueOne');

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($pageIndexerMock);

        ($this->subject)(new DataHandlerRecordDeleteEvent('sys_category', 1));

        $this->getConnectionPool()
            ->getConnectionForTable('sys_category')
            ->update('sys_category', ['deleted' => 1], ['uid' => 1]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('sys_category', 1));
    }

    /**
     * Tests that a deleted event for a record that was not announced as about
     * to be deleted removes nothing, because the context of the record is not
     * known.
     */
    #[Test]
    public function onDeletedDoesNothingForARecordThatWasNotAnnounced(): void
    {
        $this->indexerFactoryMock
            ->expects(self::never())
            ->method('makeInstanceByType');

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));
    }

    /**
     * Tests that the content elements of a deleted page are removed with the
     * UIDs that were collected before the deletion, because they are deleted
     * together with the page and can no longer be found afterwards.
     */
    #[Test]
    public function onDeletedRemovesTheContentElementsThatWereCollectedBeforeTheDeletion(): void
    {
        $contentIndexerMock = $this->createMock(ContentIndexer::class);
        $contentIndexerMock
            ->method('getTable')
            ->willReturn('tt_content');
        $contentIndexerMock
            ->method('withIndexingService')
            ->willReturn($contentIndexerMock);
        $contentIndexerMock
            ->method('dequeueOne')
            ->willReturn($contentIndexerMock);

        $removedUids = [];
        $contentIndexerMock
            ->method('dequeueMultiple')
            ->willReturnCallback(
                static function (array $uids) use (&$removedUids, $contentIndexerMock): ContentIndexer {
                    $removedUids = [...$removedUids, ...$uids];

                    return $contentIndexerMock;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($contentIndexerMock);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        // DataHandler deletes the content elements together with the page
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['pid' => 2]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        // The content elements of page 2, which can no longer be found afterwards
        foreach ([1, 2, 4, 5] as $uid) {
            self::assertContains($uid, $removedUids);
        }
    }

    /**
     * Tests that the subpages of a deleted page and all their content elements
     * are removed as well, because DataHandler deletes them together with the
     * page without a command of their own. Hidden content elements are among
     * them, and every table is removed with one batch for the whole subtree.
     */
    #[Test]
    public function onDeletedRemovesTheSubpagesAndTheirContentElements(): void
    {
        $dequeuedPages          = [];
        $dequeuedPageBatches    = 0;
        $dequeuedContent        = [];
        $dequeuedContentBatches = 0;

        $pageIndexerMock = $this->createMock(PageIndexer::class);
        $pageIndexerMock
            ->method('getTable')
            ->willReturn('pages');
        $pageIndexerMock
            ->method('withIndexingService')
            ->willReturn($pageIndexerMock);
        $pageIndexerMock
            ->method('dequeueOne')
            ->willReturnCallback(
                static function (int $uid) use (&$dequeuedPages, $pageIndexerMock): PageIndexer {
                    $dequeuedPages[] = $uid;

                    return $pageIndexerMock;
                }
            );
        $pageIndexerMock
            ->method('dequeueMultiple')
            ->willReturnCallback(
                static function (array $uids) use (&$dequeuedPages, &$dequeuedPageBatches, $pageIndexerMock): PageIndexer {
                    ++$dequeuedPageBatches;

                    foreach ($uids as $uid) {
                        $dequeuedPages[] = $uid;
                    }

                    return $pageIndexerMock;
                }
            );

        $contentIndexerMock = $this->createMock(ContentIndexer::class);
        $contentIndexerMock
            ->method('getTable')
            ->willReturn('tt_content');
        $contentIndexerMock
            ->method('withIndexingService')
            ->willReturn($contentIndexerMock);
        $contentIndexerMock
            ->method('dequeueMultiple')
            ->willReturnCallback(
                static function (array $uids) use (&$dequeuedContent, &$dequeuedContentBatches, $contentIndexerMock): ContentIndexer {
                    ++$dequeuedContentBatches;

                    foreach ($uids as $uid) {
                        $dequeuedContent[] = $uid;
                    }

                    return $contentIndexerMock;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturnCallback(
                static fn (string $type): ?IndexerInterface => match ($type) {
                    'pages'      => $pageIndexerMock,
                    'tt_content' => $contentIndexerMock,
                    default      => null,
                }
            );

        // A page below page 3 with a visible and a hidden content element of its own
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'       => 9,
                    'pid'       => 3,
                    'title'     => 'Page Below The Subpage',
                    'doktype'   => 1,
                    'deleted'   => 0,
                    'hidden'    => 0,
                    'no_search' => 0,
                ]
            );

        foreach ([[90, 0], [91, 1]] as [$uid, $hidden]) {
            $this->getConnectionPool()
                ->getConnectionForTable('tt_content')
                ->insert(
                    'tt_content',
                    [
                        'uid'     => $uid,
                        'pid'     => 9,
                        'header'  => 'Deepest Content',
                        'CType'   => 'text',
                        'deleted' => 0,
                        'hidden'  => $hidden,
                        'colPos'  => 0,
                    ]
                );
        }

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        // DataHandler deletes the subtree together with the page
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['pid' => 2]);

        foreach ([3, 9] as $pageUid) {
            $this->getConnectionPool()
                ->getConnectionForTable('pages')
                ->update('pages', ['deleted' => 1], ['uid' => $pageUid]);
            $this->getConnectionPool()
                ->getConnectionForTable('tt_content')
                ->update('tt_content', ['deleted' => 1], ['pid' => $pageUid]);
        }

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertEqualsCanonicalizing([2, 3, 9], $dequeuedPages, 'The page and the subpages are removed');
        self::assertSame(1, $dequeuedPageBatches, 'The subpages are removed in one batch');
        self::assertEqualsCanonicalizing(
            [1, 2, 4, 5, 3, 90, 91],
            $dequeuedContent,
            'The content elements of the page and of the subpages are removed, hidden ones included'
        );
        self::assertSame(1, $dequeuedContentBatches, 'The content elements are removed in one batch');
    }

    /**
     * Binds one indexer mock per table to the factory and records what each of
     * them is asked to dequeue.
     *
     * @param string[] $tables The tables to bind an indexer for
     *
     * @return array<string, array<int, int>> The UIDs dequeued by the indexer of a table, filled while the test runs
     */
    private function &bindRecordingIndexers(array $tables): array
    {
        $dequeued = [];
        $indexers = [];

        foreach ($tables as $table) {
            $dequeued[$table] = [];

            $indexer = $this->createMock(IndexerInterface::class);
            $indexer
                ->method('getTable')
                ->willReturn($table);
            $indexer
                ->method('withIndexingService')
                ->willReturn($indexer);
            $indexer
                ->method('dequeueOne')
                ->willReturnCallback(
                    static function (int $uid) use (&$dequeued, $table, $indexer): IndexerInterface {
                        $dequeued[$table][] = $uid;

                        return $indexer;
                    }
                );
            $indexer
                ->method('dequeueMultiple')
                ->willReturnCallback(
                    static function (array $uids) use (&$dequeued, $table, $indexer): IndexerInterface {
                        foreach ($uids as $uid) {
                            $dequeued[$table][] = $uid;
                        }

                        return $indexer;
                    }
                );

            $indexers[$table] = $indexer;
        }

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturnCallback(
                static fn (string $type): ?IndexerInterface => $indexers[$type] ?? null
            );

        return $dequeued;
    }

    /**
     * Tests that the translations of a deleted content element are removed
     * as well, because DataHandler deletes them without a command of their own.
     */
    #[Test]
    public function onDeletedRemovesTheTranslationsOfAContentElement(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'              => 70,
                    'pid'              => 2,
                    'header'           => 'Translation',
                    'CType'            => 'text',
                    'sys_language_uid' => 1,
                    'l18n_parent'      => 1,
                ]
            );

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('tt_content', 1));

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 70]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('tt_content', 1));

        self::assertContains(1, $dequeued['tt_content'], 'The content element is removed');
        self::assertContains(70, $dequeued['tt_content'], 'The translation of the content element is removed');
    }

    /**
     * Tests that the translations of a deleted page and of its subpages are
     * removed as well, because they are pages of their own on the parent page
     * and DataHandler deletes them without a command of their own.
     */
    #[Test]
    public function onDeletedRemovesTheTranslationsOfThePageAndItsSubpages(): void
    {
        foreach ([[71, 2], [72, 3]] as [$uid, $parent]) {
            $this->getConnectionPool()
                ->getConnectionForTable('pages')
                ->insert(
                    'pages',
                    [
                        'uid'              => $uid,
                        'pid'              => 1,
                        'title'            => 'Translated Page',
                        'doktype'          => 1,
                        'sys_language_uid' => 1,
                        'l10n_parent'      => $parent,
                    ]
                );
        }

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 71]);
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 72]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertContains(71, $dequeued['pages'], 'The translation of the page is removed');
        self::assertContains(72, $dequeued['pages'], 'The translation of the subpage is removed');
    }

    /**
     * Tests that the records of other indexed tables that are stored on a
     * deleted page or on one of its subpages are removed as well.
     */
    #[Test]
    public function onDeletedRemovesRecordsOfOtherIndexedTablesOnTheDeletedTree(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer']['sys_template'] = [
            'className' => ContentIndexer::class,
            'tableName' => 'sys_template',
            'title'     => 'Templates',
            'icon'      => null,
        ];

        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 6,
                    'pid'             => 1,
                    'title'           => 'Template Indexer',
                    'type'            => 'sys_template',
                    'search_engine'   => 1,
                    'deleted'         => 0,
                    'pages_recursive' => '1',
                ]
            );

        // A record of the other table on the deleted page, one on its subpage and one elsewhere
        foreach ([[40, 2], [41, 3], [42, 4]] as [$uid, $pid]) {
            $this->getConnectionPool()
                ->getConnectionForTable('sys_template')
                ->insert('sys_template', ['uid' => $uid, 'pid' => $pid, 'title' => 'Template']);
        }

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content', 'sys_template']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        // DataHandler deletes the records of the tree together with the page
        $this->getConnectionPool()
            ->getConnectionForTable('sys_template')
            ->update('sys_template', ['deleted' => 1], ['uid' => 40]);
        $this->getConnectionPool()
            ->getConnectionForTable('sys_template')
            ->update('sys_template', ['deleted' => 1], ['uid' => 41]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertEqualsCanonicalizing([40, 41], $dequeued['sys_template']);
    }

    /**
     * Tests that the page tree is still removed when the indexing service of
     * the index is stored in the deleted tree itself and is deleted with it,
     * because the services are resolved before the deletion.
     */
    #[Test]
    public function onDeletedRemovesTheTreeWhenTheIndexingServiceIsDeletedWithIt(): void
    {
        $connection = $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice');

        // The only page indexing service lives on the page that is deleted
        $connection->update('tx_typo3searchalgolia_domain_model_indexingservice', ['deleted' => 1], ['uid' => 1]);
        $connection->insert(
            'tx_typo3searchalgolia_domain_model_indexingservice',
            [
                'uid'             => 7,
                'pid'             => 2,
                'title'           => 'Page Indexer Of The Deleted Tree',
                'type'            => 'pages',
                'search_engine'   => 1,
                'deleted'         => 0,
                'pages_recursive' => '1',
                'pages_doktype'   => '1',
            ]
        );

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        // DataHandler deletes the tree together with the service stored in it
        $connection->update('tx_typo3searchalgolia_domain_model_indexingservice', ['deleted' => 1], ['uid' => 7]);
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 2]);

        $this->markPagesDeleted([3]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertEqualsCanonicalizing([2, 3], $dequeued['pages']);
    }

    /**
     * Inserts a translated page 71 of page 2 with a translated content element 70.
     *
     * @return void
     */
    private function insertTranslatedPage(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'              => 71,
                    'pid'              => 1,
                    'title'            => 'Translated Page',
                    'doktype'          => 1,
                    'sys_language_uid' => 1,
                    'l10n_parent'      => 2,
                ]
            );

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'              => 70,
                    'pid'              => 2,
                    'header'           => 'Translated Content',
                    'CType'            => 'text',
                    'sys_language_uid' => 1,
                    'l18n_parent'      => 1,
                ]
            );
    }

    /**
     * Tests that the child elements of a deleted content element are removed
     * with it, because DataHandler deletes the children of an inline relation
     * and its translations without a command of their own. A child that is
     * still there keeps its document.
     */
    #[Test]
    public function onDeletedRemovesTheInlineChildrenOfAContentElement(): void
    {
        $this->addInlineChildRelation();

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Content element 1 has the children 80 and 81 and the translation 70 of child 80
        foreach ([[80, 1, 0, 0], [81, 1, 0, 0], [70, 0, 80, 1]] as [$uid, $parent, $translationOf, $language]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 2,
                    'header'           => 'Child',
                    'CType'            => 'text',
                    'sys_language_uid' => $language,
                    'l18n_parent'      => $translationOf,
                    'tx_test_parent'   => $parent,
                ]
            );
        }

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('tt_content', 1));

        // The child 81 stays, for example because the user may not edit it
        foreach ([1, 80, 70] as $uid) {
            $connection->update('tt_content', ['deleted' => 1], ['uid' => $uid]);
        }

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('tt_content', 1));

        self::assertEqualsCanonicalizing([1, 80, 70], $dequeued['tt_content']);
    }

    /**
     * Marks pages as deleted, the way DataHandler deletes the subpages of a tree
     * together with the page.
     *
     * @param int[] $pageUids The UIDs of the pages
     *
     * @return void
     */
    private function markPagesDeleted(array $pageUids): void
    {
        foreach ($pageUids as $pageUid) {
            $this->getConnectionPool()
                ->getConnectionForTable('pages')
                ->update('pages', ['deleted' => 1], ['uid' => $pageUid]);
        }
    }

    /**
     * Registers sys_file_reference as an indexed table with a content indexing service, so
     * that records of it are collected with the ones of a deleted record.
     *
     * @return void
     */
    private function registerFileReferenceIndexer(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer']['sys_file_reference'] = [
            'className' => ContentIndexer::class,
            'tableName' => 'sys_file_reference',
            'title'     => 'File references',
            'icon'      => null,
        ];

        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 6,
                    'pid'             => 1,
                    'title'           => 'File Reference Indexer',
                    'type'            => 'sys_file_reference',
                    'search_engine'   => 1,
                    'deleted'         => 0,
                    'pages_recursive' => '1',
                ]
            );
    }

    /**
     * Tests that a translation of a deleted content element is removed together
     * with the inline children of that translation, which DataHandler deletes
     * with it, and that the translation is not lost when children share the table.
     */
    #[Test]
    public function onDeletedRemovesTheTranslationAndItsInlineChildrenOfAContentElement(): void
    {
        $this->addInlineChildRelation();

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Content element 1 has the child 80, its translation is 72, and the translation has the child 82
        foreach ([[80, 1, 0, 0], [72, 0, 1, 1], [82, 72, 0, 1]] as [$uid, $parent, $translationOf, $language]) {
            $connection->insert(
                'tt_content',
                [
                    'uid'              => $uid,
                    'pid'              => 2,
                    'header'           => 'Related',
                    'CType'            => 'text',
                    'sys_language_uid' => $language,
                    'l18n_parent'      => $translationOf,
                    'tx_test_parent'   => $parent,
                ]
            );
        }

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('tt_content', 1));

        foreach ([1, 80, 72, 82] as $uid) {
            $connection->update('tt_content', ['deleted' => 1], ['uid' => $uid]);
        }

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('tt_content', 1));

        self::assertEqualsCanonicalizing([1, 80, 72, 82], $dequeued['tt_content']);
    }

    /**
     * Tests that the content elements that are attached to a record of another
     * indexed table are removed with it, which only happens when content elements
     * are asked for as child records of every table.
     */
    #[Test]
    public function onDeletedRemovesTheContentElementsAttachedToARecordOfAnotherIndexedTable(): void
    {
        $this->addInlineChildRelation();
        $this->registerFileReferenceIndexer();

        $GLOBALS['TCA']['sys_file_reference']['columns']['tx_test_children'] = [
            'config' => [
                'type'          => 'inline',
                'foreign_table' => 'tt_content',
                'foreign_field' => 'tx_test_parent',
            ],
        ];

        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', ['uid' => 51, 'pid' => 2, 'sys_language_uid' => 0]);

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        $connection->insert(
            'tt_content',
            [
                'uid'            => 80,
                'pid'            => 2,
                'header'         => 'Attached',
                'CType'          => 'text',
                'tx_test_parent' => 51,
            ]
        );

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content', 'sys_file_reference']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('sys_file_reference', 51));

        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['deleted' => 1], ['uid' => 51]);
        $connection->update('tt_content', ['deleted' => 1], ['uid' => 80]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('sys_file_reference', 51));

        self::assertSame([80], $dequeued['tt_content']);
    }

    /**
     * Tests that the records of another indexed table that are attached to a
     * deleted content element are removed with it.
     */
    #[Test]
    public function onDeletedRemovesTheRecordsOfAnotherIndexedTableAttachedToAContentElement(): void
    {
        $this->registerFileReferenceIndexer();

        $GLOBALS['TCA']['tt_content']['columns']['tx_test_files'] = [
            'config' => [
                'type'          => 'inline',
                'foreign_table' => 'sys_file_reference',
                'foreign_field' => 'uid_foreign',
            ],
        ];

        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_reference');

        // Reference 50 belongs to content element 1, reference 51 to content element 2
        $connection->insert('sys_file_reference', ['uid' => 50, 'pid' => 2, 'uid_foreign' => 1]);
        $connection->insert('sys_file_reference', ['uid' => 51, 'pid' => 2, 'uid_foreign' => 2]);

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content', 'sys_file_reference']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('tt_content', 1));

        $connection->update('sys_file_reference', ['deleted' => 1], ['uid' => 50]);
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 1]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('tt_content', 1));

        self::assertSame([50], $dequeued['sys_file_reference']);
    }

    /**
     * Tests that deleting the translation of a page removes the content of
     * that language as well. It is stored on the default language page and
     * DataHandler deletes it together with the translation.
     */
    #[Test]
    public function onDeletedRemovesTheContentOfADeletedPageTranslation(): void
    {
        $this->insertTranslatedPage();

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 71));

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 71]);
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 70]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 71));

        self::assertSame([71], $dequeued['pages'], 'Only the translation is removed, not its default language page');
        self::assertSame([70], $dequeued['tt_content'], 'Only the content of the language is removed');
    }

    /**
     * Tests that the records of other indexed tables that are stored on the page
     * of the default language are removed with the translation of a page, when
     * the table can be localized.
     */
    #[Test]
    public function onDeletedRemovesRecordsOfOtherIndexedTablesOfAPageTranslation(): void
    {
        $this->insertTranslatedPage();

        // A table that can be localized, stored on the page of the default language
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer']['sys_file_reference'] = [
            'className' => ContentIndexer::class,
            'tableName' => 'sys_file_reference',
            'title'     => 'File references',
            'icon'      => null,
        ];

        $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 6,
                    'pid'             => 1,
                    'title'           => 'File Reference Indexer',
                    'type'            => 'sys_file_reference',
                    'search_engine'   => 1,
                    'deleted'         => 0,
                    'pages_recursive' => '1',
                ]
            );

        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', ['uid' => 50, 'pid' => 2, 'sys_language_uid' => 1]);
        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->insert('sys_file_reference', ['uid' => 51, 'pid' => 2, 'sys_language_uid' => 0]);

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content', 'sys_file_reference']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 71));

        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 71]);
        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['deleted' => 1], ['uid' => 50]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 71));

        self::assertSame([50], $dequeued['sys_file_reference'], 'Only the record of the language is removed');
    }

    /**
     * Tests that a record of the language that DataHandler did not delete keeps
     * its document when the translation of a page is deleted. The default page
     * stays, so every record is checked on its own, for example for an edit lock.
     */
    #[Test]
    public function onDeletedKeepsTheRecordOfAPageTranslationThatWasNotDeleted(): void
    {
        $this->insertTranslatedPage();

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 71));

        // Only the translation is deleted, content element 70 stays live
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 71]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 71));

        self::assertSame([71], $dequeued['pages']);
        self::assertSame([], $dequeued['tt_content']);
    }

    /**
     * Tests that the pages below a recycler or backend user section page are
     * collected as well, because DataHandler deletes the whole branch.
     */
    #[Test]
    public function onDeletedRemovesThePagesBelowARecyclerPage(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');

        foreach ([[80, 3, 255], [81, 80, 1]] as [$uid, $pid, $doktype]) {
            $connection->insert(
                'pages',
                ['uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => $doktype]
            );
        }

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                ['uid' => 82, 'pid' => 81, 'header' => 'Below Recycler', 'CType' => 'text']
            );

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 82]);

        $this->markPagesDeleted([3, 80, 81]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertEqualsCanonicalizing([2, 3, 80, 81], $dequeued['pages']);
        self::assertContains(82, $dequeued['tt_content']);
    }

    /**
     * Tests that a translation that DataHandler did not delete, because the
     * user may not edit it, keeps its document.
     */
    #[Test]
    public function onDeletedKeepsTheTranslationThatWasNotDeleted(): void
    {
        $this->insertTranslatedPage();

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('tt_content', 1));

        // Content element 1 is deleted, its translation 70 stays live
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 1]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('tt_content', 1));

        self::assertContains(1, $dequeued['tt_content']);
        self::assertNotContains(70, $dequeued['tt_content']);
    }

    /**
     * Tests that a site root below the deleted page is removed with the
     * indexing services of its own site.
     */
    #[Test]
    public function onDeletedUsesTheServicesOfASiteRootBelowTheDeletedPage(): void
    {
        $connectionPool = $this->getConnectionPool();

        $connectionPool
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                ['uid' => 85, 'pid' => 3, 'title' => 'Nested Site', 'doktype' => 1, 'is_siteroot' => 1]
            );
        $connectionPool
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'             => 8,
                    'pid'             => 85,
                    'title'           => 'Nested Site Page Indexer',
                    'type'            => 'pages',
                    'search_engine'   => 1,
                    'deleted'         => 0,
                    'pages_recursive' => '85',
                    'pages_doktype'   => '1',
                ]
            );

        $byService = [];
        $factory   = $this->createMock(IndexerInterface::class);
        $factory
            ->method('withIndexingService')
            ->willReturnCallback(
                function (IndexingService $service) use (&$byService): IndexerInterface {
                    $indexer = $this->createMock(IndexerInterface::class);
                    $indexer->method('getTable')->willReturn('pages');
                    $indexer->method('dequeueOne')->willReturn($indexer);
                    $indexer
                        ->method('dequeueMultiple')
                        ->willReturnCallback(
                            static function (array $uids) use (&$byService, $service, $indexer): IndexerInterface {
                                foreach ($uids as $uid) {
                                    $byService[(int) $service->getUid()][] = $uid;
                                }

                                return $indexer;
                            }
                        );

                    return $indexer;
                }
            );

        $this->indexerFactoryMock
            ->method('makeInstanceByType')
            ->willReturn($factory);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        $this->markPagesDeleted([3, 85]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertContains(85, $byService[8] ?? [], 'The service of the nested site removes the nested site root');
    }

    /**
     * Tests that the listener collects nothing for a record that does not
     * exist, so that a delete command for it neither fails nor removes anything.
     */
    #[Test]
    public function invokeCollectsNothingForANonExistentRecord(): void
    {
        $this->indexerFactoryMock
            ->expects(self::never())
            ->method('makeInstanceByType');

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 999));
        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 999));
    }

    /**
     * Binds a search engine double that records every removed document as
     * table and record UID.
     *
     * @return string[] The removed documents, filled while the test runs
     */
    private function &bindRecordingSearchEngine(): array
    {
        $removed      = [];
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $uids) use (&$removed): void {
                    foreach ($uids as $uid) {
                        $removed[] = $table . ':' . $uid;
                    }
                }
            );

        $this->searchEngineFactoryMock
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        return $removed;
    }

    /**
     * Tests that a subpage that DataHandler did not delete keeps its document
     * when the tree is deleted, which happens when the delete does not go
     * through the all-or-nothing check, as for the publish of a workspace delete.
     */
    #[Test]
    public function onDeletedKeepsTheDocumentOfASubpageThatWasNotDeleted(): void
    {
        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        // Page 2 is deleted, its subpage 3 stays live
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 2]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertSame([2], $dequeued['pages']);
    }

    /**
     * Tests that a content element that DataHandler left behind while it
     * deleted the tree keeps its document, in the queue and in the index,
     * and that the elements that are gone lose theirs.
     */
    #[Test]
    public function onDeletedKeepsTheDocumentOfAContentElementThatStaysLiveInADeletedTree(): void
    {
        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);
        $removed  = &$this->bindRecordingSearchEngine();

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        // Content element 5 stays, for example because the user may not edit it
        foreach ([1, 2, 3, 4] as $uid) {
            $this->getConnectionPool()
                ->getConnectionForTable('tt_content')
                ->update('tt_content', ['deleted' => 1], ['uid' => $uid]);
        }

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertEqualsCanonicalizing([1, 2, 3, 4], $dequeued['tt_content'], 'The queue entry of element 5 stays');

        foreach ([1, 2, 3, 4] as $uid) {
            self::assertContains('tt_content:' . $uid, $removed);
        }

        self::assertNotContains('tt_content:5', $removed, 'The document of element 5 stays in the index');
    }

    /**
     * Tests that the translation of a subpage that DataHandler left behind
     * while it deleted the tree keeps its document, and that the pages of the
     * tree, which are deleted together, are removed without a check.
     */
    #[Test]
    public function onDeletedKeepsTheDocumentOfAPageTranslationThatStaysLiveInADeletedTree(): void
    {
        foreach ([[71, 2], [72, 3]] as [$uid, $parent]) {
            $this->getConnectionPool()
                ->getConnectionForTable('pages')
                ->insert(
                    'pages',
                    [
                        'uid'              => $uid,
                        'pid'              => 1,
                        'title'            => 'Translated Page',
                        'doktype'          => 1,
                        'sys_language_uid' => 1,
                        'l10n_parent'      => $parent,
                    ]
                );
        }

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);
        $removed  = &$this->bindRecordingSearchEngine();

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 2));

        // The translation of the page is deleted, the one of the subpage stays
        $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 71]);

        $this->markPagesDeleted([3]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 2));

        self::assertEqualsCanonicalizing([2, 3, 71], $dequeued['pages']);
        self::assertNotContains('pages:72', $removed, 'The document of the live translation stays in the index');
        self::assertContains('pages:71', $removed);
    }

    /**
     * Tests that a page with a language but without a page of the default
     * language is no translation, so the tree below it is deleted with it.
     */
    #[Test]
    public function onDeletedTakesThePathOfATreeForAPageWithALanguageButNoDefaultPage(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');

        $connection->insert(
            'pages',
            ['uid' => 90, 'pid' => 1, 'title' => 'Language Page', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 0]
        );
        $connection->insert(
            'pages',
            ['uid' => 91, 'pid' => 90, 'title' => 'Page Below', 'doktype' => 1]
        );

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->insert(
                'tt_content',
                ['uid' => 92, 'pid' => 91, 'header' => 'Below The Language Page', 'CType' => 'text']
            );

        $dequeued = &$this->bindRecordingIndexers(['pages', 'tt_content']);

        ($this->subject)(new DataHandlerRecordDeleteEvent('pages', 90));

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 92]);

        $this->markPagesDeleted([91]);

        $this->subject->onDeleted(new DataHandlerRecordDeletedEvent('pages', 90));

        self::assertEqualsCanonicalizing([90, 91], $dequeued['pages'], 'The subpage is removed with the page');
        self::assertSame([92], $dequeued['tt_content']);
    }
}
