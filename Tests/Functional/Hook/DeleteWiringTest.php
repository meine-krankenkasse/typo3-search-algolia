<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Hook;

use FilesystemIterator;
use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource\AfterFileDeletedEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Hook\Fixtures\NestedDeleteHook;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Processing\FileDeletionAspect;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_map;
use function array_search;
use function file_get_contents;
use function intval;
use function is_array;
use function is_file;
use function preg_match;
use function preg_match_all;

/**
 * Functional tests for the wiring of the delete path through the real
 * container, the real DataHandler, the hook, the event listeners and the real
 * indexers. Nothing of the extension except the search engine factory is
 * replaced, so a listener that is not registered or a service that cannot be
 * built fails here and not only in the backend.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversNothing]
final class DeleteWiringTest extends AbstractFunctionalTestCase
{
    /**
     * Core extensions required for the test. The scheduler is loaded, because one listener
     * of the extension is ordered after a listener of the scheduler.
     *
     * @var array<non-empty-string>
     */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'scheduler',
    ];

    /**
     * Imports the page tree, the indexing services and an admin user, and
     * queues pages 2, 3 and 4 for the page indexing service.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_searchengine.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_typo3searchalgolia_domain_model_indexingservice.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users.csv');

        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $connection = $this->getConnectionPool()
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_queueitem');

        foreach ([2, 3, 4] as $pageUid) {
            $connection->insert(
                'tx_typo3searchalgolia_domain_model_queueitem',
                [
                    'pid'         => 0,
                    'table_name'  => 'pages',
                    'record_uid'  => $pageUid,
                    'service_uid' => 1,
                ],
            );
        }
    }

    /**
     * Returns the UIDs of the pages in the indexing queue.
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
                    $queryBuilder->createNamedParameter('pages'),
                ),
            )
            ->orderBy('record_uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(intval(...), $recordUids);
    }

    /**
     * Replaces the search engine factory of the container by a double that
     * records the removed documents, so no request leaves the test.
     *
     * @param list<string> $removed Receives the removed documents as table:uid
     *
     * @return void
     */
    private function recordRemovedDocuments(array &$removed): void
    {
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
                },
            );

        $searchEngineFactory = $this->createMock(SearchEngineFactory::class);
        $searchEngineFactory
            ->method('makeInstanceBySearchEngineModel')
            ->willReturn($searchEngine);

        $container = $this->getContainer();

        self::assertInstanceOf(ContainerInterface::class, $container);

        // The factory is replaced before the record handler is built for the first time
        $container->set(SearchEngineFactory::class, $searchEngineFactory);
    }

    /**
     * Tests that a delete of a page through the real DataHandler removes the
     * queue rows of the page and of its subpage, and leaves the rows of the
     * pages that stay. The search engine factory is replaced by a double, so no
     * request leaves the test, and the removal from the index reaches it.
     */
    #[Test]
    public function aDeleteOfAPageRemovesTheQueueRowsOfThePageAndItsSubpage(): void
    {
        $removed = [];

        $this->recordRemovedDocuments($removed);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->start([], ['pages' => [2 => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        self::assertSame([], $dataHandler->errorLog);
        self::assertSame([4], $this->findQueuedPageUids(), 'Only the page that stays is queued');
        self::assertContains('pages:2', $removed);
        self::assertContains('pages:3', $removed);
        self::assertNotContains('pages:4', $removed);
    }

    /**
     * Tests that the delete of a record is still announced as carried out when a
     * hook runs a nested DataHandler for another record while the command is in
     * progress, the way a container element extension deletes its children.
     */
    #[Test]
    public function aNestedCommandDoesNotMakeTheOuterDeleteLookRefused(): void
    {
        $removed = [];

        $this->recordRemovedDocuments($removed);

        NestedDeleteHook::reset();

        NestedDeleteHook::$triggerUid = 2;
        NestedDeleteHook::$nestedUid  = 4;

        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
            = NestedDeleteHook::class;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

            $dataHandler->start([], ['pages' => [2 => ['delete' => 1]]]);
            $dataHandler->process_cmdmap();
        } finally {
            NestedDeleteHook::reset();
        }

        self::assertSame([], $this->findQueuedPageUids(), 'The pages of both commands are dequeued');
        self::assertContains('pages:2', $removed, 'The page of the outer command is removed');
        self::assertContains('pages:3', $removed);
        self::assertContains('pages:4', $removed);
    }

    /**
     * Tests that the listener that reacts to a deleted file is called after
     * the listener of the core that removes the processed files of the file,
     * because the listener of the extension relies on the state that listener
     * leaves. The order that this checks is the effective one. The clause in
     * the service configuration that asks for it is pinned by the unit test of
     * the listener registration, because the default order of the packages
     * already puts the core listener first.
     */
    #[Test]
    public function theFileDeletedListenerRunsAfterTheCoreListenerThatRemovesProcessedFiles(): void
    {
        $event = new AfterFileDeletedEvent(self::createStub(FileInterface::class));

        $listenerClasses = [];

        foreach ($this->get(ListenerProvider::class)->getListenersForEvent($event) as $listener) {
            $target            = is_array($listener) ? $listener[0] : $listener;
            $listenerClasses[] = $target::class;
        }

        $coreIndex      = array_search(FileDeletionAspect::class, $listenerClasses, true);
        $extensionIndex = array_search(AfterFileDeletedEventListener::class, $listenerClasses, true);

        self::assertNotFalse($coreIndex, 'The core listener is registered');
        self::assertNotFalse($extensionIndex, 'The listener of the extension is registered');
        self::assertLessThan($extensionIndex, $coreIndex);
    }

    /**
     * Tests that every listener the service configuration of the extension asks to run
     * after another one names an identifier that the listeners of the core or of the scheduler declare. A
     * clause that names an identifier nobody declares is dropped without a message, so
     * the order the extension relies on would no longer be asked for.
     */
    #[Test]
    public function everyListenerPredecessorOfTheExtensionIsDeclaredByTheCoreOrTheScheduler(): void
    {
        $extensionServices = Yaml::parseFile(ExtensionManagementUtility::extPath('typo3_search_algolia') . 'Configuration/Services.yaml');

        $declared = [];

        // The listeners of the core and of the scheduler, which one of the registrations orders itself after
        foreach (['core', 'scheduler'] as $extensionKey) {
            $servicesFile = ExtensionManagementUtility::extPath($extensionKey) . 'Configuration/Services.yaml';

            if (is_file($servicesFile)) {
                $services = Yaml::parseFile($servicesFile, Yaml::PARSE_CUSTOM_TAGS);

                foreach ($services['services'] ?? [] as $service) {
                    foreach ($service['tags'] ?? [] as $tag) {
                        if (isset($tag['identifier'])) {
                            $declared[] = $tag['identifier'];
                        }
                    }
                }
            }

            // A listener is declared either in the service configuration or by an attribute. An attribute
            // without an identifier takes the name of its class.
            $classFiles = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(ExtensionManagementUtility::extPath($extensionKey) . 'Classes', FilesystemIterator::SKIP_DOTS),
            );

            foreach ($classFiles as $classFile) {
                if (
                    !($classFile instanceof SplFileInfo)
                    || ($classFile->getExtension() !== 'php')
                ) {
                    continue;
                }

                $source = (string) file_get_contents($classFile->getPathname());

                if (preg_match_all("/#\\[AsEventListener\\(\\s*(?:identifier:\\s*)?'([^']+)'/", $source, $matches) > 0) {
                    $declared = [...$declared, ...$matches[1]];
                }

                if (
                    (preg_match('/#\\[AsEventListener\\]/', $source) === 1)
                    && (preg_match('/^namespace\\s+([^;]+);/m', $source, $namespace) === 1)
                    && (preg_match('/^(?:final\\s+|readonly\\s+)*class\\s+(\\w+)/m', $source, $className) === 1)
                ) {
                    $declared[] = $namespace[1] . '\\' . $className[1];
                }
            }
        }

        $predecessors = [];

        foreach ($extensionServices['services'] as $service) {
            foreach ($service['tags'] ?? [] as $tag) {
                if (isset($tag['after'])) {
                    $predecessors[] = $tag['after'];
                }
            }
        }

        self::assertNotSame([], $predecessors, 'The extension asks for an order');

        foreach ($predecessors as $predecessor) {
            self::assertContains($predecessor, $declared, 'The core or the scheduler declares the listener ' . $predecessor);
        }
    }
}
