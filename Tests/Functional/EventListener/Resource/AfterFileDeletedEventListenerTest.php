<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\EventListener\Resource;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeletedEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordDeleteEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileDeletedEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\RegistersEventSpyTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;

use function file_put_contents;

/**
 * Functional tests for AfterFileDeletedEventListener.
 *
 * The file is deleted through the real resource storage, so that the order of
 * the events of TYPO3 and the removal of the metadata record by TYPO3 are the
 * real ones.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AfterFileDeletedEventListener::class)]
final class AfterFileDeletedEventListenerTest extends AbstractFunctionalTestCase
{
    use RegistersEventSpyTrait;

    /**
     * Imports the backend user that may delete files.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/be_users.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Tests that deleting a file through the resource storage announces the
     * metadata record of the file as about to be deleted and as deleted, with
     * the UID the record had before TYPO3 removed it.
     */
    #[Test]
    public function deletingAFileAnnouncesItsMetadataRecord(): void
    {
        file_put_contents($this->instancePath . '/fileadmin/to-delete.pdf', 'content');

        $storageUid = $this->get(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin', 'relative', '', true);
        $storage = $this->get(StorageRepository::class)->findByUid((int) $storageUid);

        self::assertNotNull($storage);

        $file = $storage->getFile('/to-delete.pdf');

        self::assertInstanceOf(File::class, $file);

        $metadataUid = (int) $file->getMetaData()->offsetGet('uid');

        self::assertGreaterThan(0, $metadataUid, 'The file has a metadata record');

        $deleteSpy  = $this->registerEventSpy(DataHandlerRecordDeleteEvent::class);
        $deletedSpy = $this->registerEventSpy(DataHandlerRecordDeletedEvent::class);

        $storage->deleteFile($file);

        self::assertCount(1, $deleteSpy->events, 'The delete event is dispatched once');
        self::assertCount(1, $deletedSpy->events, 'The deleted event is dispatched once');

        $deleteEvent  = $deleteSpy->events[0];
        $deletedEvent = $deletedSpy->events[0];

        self::assertInstanceOf(DataHandlerRecordDeleteEvent::class, $deleteEvent);
        self::assertInstanceOf(DataHandlerRecordDeletedEvent::class, $deletedEvent);
        self::assertSame($metadataUid, $deleteEvent->getRecordUid());
        self::assertSame($metadataUid, $deletedEvent->getRecordUid());
        self::assertSame('sys_file_metadata', $deletedEvent->getTable());
    }

    /**
     * Tests that deleting a file removes the queue entry and the document of its
     * metadata record, through the indexing service that indexes files.
     */
    #[Test]
    public function deletingAFileRemovesItsQueueEntryAndItsDocument(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');

        $connectionPool = $this->getConnectionPool();

        $connectionPool
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->insert(
                'tx_typo3searchalgolia_domain_model_indexingservice',
                [
                    'uid'           => 5,
                    'pid'           => 0,
                    'title'         => 'File Indexer',
                    'type'          => 'sys_file_metadata',
                    'search_engine' => 1,
                ]
            );

        file_put_contents($this->instancePath . '/fileadmin/to-delete.pdf', 'content');

        $storageUid = $this->get(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin', 'relative', '', true);
        $storage = $this->get(StorageRepository::class)->findByUid((int) $storageUid);

        self::assertNotNull($storage);

        $file = $storage->getFile('/to-delete.pdf');

        self::assertInstanceOf(File::class, $file);

        $metadataUid = (int) $file->getMetaData()->offsetGet('uid');

        $queue = $connectionPool->getConnectionForTable('tx_typo3searchalgolia_domain_model_queueitem');
        $queue->insert(
            'tx_typo3searchalgolia_domain_model_queueitem',
            [
                'pid'         => 0,
                'table_name'  => 'sys_file_metadata',
                'record_uid'  => $metadataUid,
                'service_uid' => 5,
            ]
        );

        $removals     = [];
        $searchEngine = $this->createMock(SearchEngineInterface::class);
        $searchEngine
            ->method('withIndexName')
            ->willReturn($searchEngine);
        $searchEngine
            ->method('deleteManyFromIndex')
            ->willReturnCallback(
                static function (string $table, array $recordUids) use (&$removals): void {
                    $removals[] = [$table, $recordUids];
                }
            );

        $searchEngineFactory = $this->createMock(SearchEngineFactory::class);
        $searchEngineFactory
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $container = $this->getContainer();

        self::assertInstanceOf(Container::class, $container);

        $container->set(SearchEngineFactory::class, $searchEngineFactory);

        $storage->deleteFile($file);

        self::assertSame(
            0,
            $queue->count('*', 'tx_typo3searchalgolia_domain_model_queueitem', ['record_uid' => $metadataUid]),
            'The queue entry of the metadata record is gone'
        );
        self::assertSame([['sys_file_metadata', [$metadataUid]]], $removals, 'And its document is removed');
    }
}
