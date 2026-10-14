<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\SearchEngine;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Model\Search\DeletedAtResponse;
use Algolia\AlgoliaSearch\Model\Search\SaveObjectResponse;
use Algolia\AlgoliaSearch\Model\Search\UpdatedAtResponse;
use Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\MissingConfigurationException;
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\RateLimitException;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine\AbstractSearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine\AlgoliaSearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine\SearchClientFactoryInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function implode;
use function preg_quote;

/**
 * Unit tests for AlgoliaSearchEngine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AlgoliaSearchEngine::class)]
#[CoversClass(AbstractSearchEngine::class)]
#[UsesClass(CreateUniqueDocumentIdEvent::class)]
final class AlgoliaSearchEngineTest extends TestCase
{
    private MockObject&EventDispatcherInterface $eventDispatcherMock;

    private MockObject&ExtensionConfiguration $extensionConfigMock;

    private MockObject&SearchClientFactoryInterface $searchClientFactoryMock;

    private MockObject&SearchClient $searchClientMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcherMock     = $this->createMock(EventDispatcherInterface::class);
        $this->extensionConfigMock     = $this->createMock(ExtensionConfiguration::class);
        $this->searchClientFactoryMock = $this->createMock(SearchClientFactoryInterface::class);
        $this->searchClientMock        = $this->createMock(SearchClient::class);

        $this->extensionConfigMock
            ->method('get')
            ->with(Constants::EXTENSION_NAME)
            ->willReturn([
                'appId'  => 'test-app-id',
                'apiKey' => 'test-api-key',
            ]);

        $this->searchClientFactoryMock
            ->method('create')
            ->with('test-app-id', 'test-api-key')
            ->willReturn($this->searchClientMock);
    }

    private function createEngine(): AlgoliaSearchEngine
    {
        return new AlgoliaSearchEngine(
            $this->eventDispatcherMock,
            $this->extensionConfigMock,
            $this->searchClientFactoryMock,
        );
    }

    /**
     * Sets up event dispatcher to return a CreateUniqueDocumentIdEvent with a given document ID.
     */
    private function configureEventDispatcher(string $documentId = 'pages-42'): void
    {
        $this->eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(static function (CreateUniqueDocumentIdEvent $event) use ($documentId): CreateUniqueDocumentIdEvent {
                $event->setDocumentId($documentId);

                return $event;
            });
    }

    /**
     * Creates a Document mock with standard test values.
     */
    private function createDocumentMock(): MockObject&Document
    {
        $fields = ['title' => 'Test'];

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $documentMock = $this->createMock(Document::class);
        $documentMock
            ->method('getIndexer')
            ->willReturn($indexerMock);
        $documentMock
            ->method('getRecord')
            ->willReturn(['uid' => 42]);

        // Track setField calls so getFields returns accumulated fields
        $documentMock
            ->method('setField')
            ->willReturnCallback(static function (string $name, mixed $value) use (&$fields, $documentMock): Document {
                $fields[$name] = $value;

                return $documentMock;
            });
        $documentMock
            ->method('getFields')
            ->willReturnCallback(static function () use (&$fields): array {
                return $fields;
            });

        return $documentMock;
    }

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /**
     * Tests that the constructor throws a MissingConfigurationException
     * when the Algolia application ID is set to false.
     */
    #[Test]
    public function constructorThrowsExceptionWhenAppIdIsFalse(): void
    {
        $this->extensionConfigMock = $this->createMock(ExtensionConfiguration::class);
        $this->extensionConfigMock
            ->method('get')
            ->willReturn([
                'appId'  => false,
                'apiKey' => 'test-api-key',
            ]);

        $this->expectException(MissingConfigurationException::class);

        $this->createEngine();
    }

    /**
     * Tests that the constructor throws a MissingConfigurationException
     * when the Algolia API key is set to false.
     */
    #[Test]
    public function constructorThrowsExceptionWhenApiKeyIsFalse(): void
    {
        $this->extensionConfigMock = $this->createMock(ExtensionConfiguration::class);
        $this->extensionConfigMock
            ->method('get')
            ->willReturn([
                'appId'  => 'test-app-id',
                'apiKey' => false,
            ]);

        $this->expectException(MissingConfigurationException::class);

        $this->createEngine();
    }

    /**
     * Tests that the constructor invokes the SearchClientFactory exactly once
     * with the configured application ID and API key.
     */
    #[Test]
    public function constructorCallsFactoryWithCredentials(): void
    {
        $this->searchClientFactoryMock = $this->createMock(SearchClientFactoryInterface::class);
        $this->searchClientFactoryMock
            ->expects(self::once())
            ->method('create')
            ->with('test-app-id', 'test-api-key')
            ->willReturn($this->searchClientMock);

        $this->createEngine();
    }

    // -----------------------------------------------------------------------
    // indexOpen / indexClose
    // -----------------------------------------------------------------------

    /**
     * Tests that indexClose() resets the index name, causing a subsequent
     * documentAdd() call to throw a RuntimeException.
     */
    #[Test]
    public function indexCloseResetsIndexName(): void
    {
        $engine = $this->createEngine();

        $engine->indexOpen('test_index');
        $engine->indexClose();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index name not set');

        $engine->documentAdd($this->createDocumentMock());
    }

    // -----------------------------------------------------------------------
    // indexExists
    // -----------------------------------------------------------------------

    /**
     * Tests that indexExists() returns true when the Algolia client
     * confirms the index exists.
     */
    #[Test]
    public function indexExistsReturnsTrueWhenIndexExists(): void
    {
        $this->searchClientMock
            ->method('indexExists')
            ->with('test_index')
            ->willReturn(true);

        $engine = $this->createEngine();

        self::assertTrue($engine->indexExists('test_index'));
    }

    /**
     * Tests that indexExists() returns false when the Algolia client
     * throws an exception during the existence check.
     */
    #[Test]
    public function indexExistsReturnsFalseOnException(): void
    {
        $this->searchClientMock
            ->method('indexExists')
            ->willThrowException(new Exception('API error'));

        $engine = $this->createEngine();

        self::assertFalse($engine->indexExists('test_index'));
    }

    // -----------------------------------------------------------------------
    // indexDelete
    // -----------------------------------------------------------------------

    /**
     * Tests that indexDelete() calls deleteIndex() on the client and returns
     * the validation result from the DeletedAtResponse.
     */
    #[Test]
    public function indexDeleteCallsClientAndReturnsResult(): void
    {
        $responseMock = $this->createMock(DeletedAtResponse::class);
        $responseMock
            ->method('valid')
            ->willReturn(true);

        $this->searchClientMock
            ->expects(self::once())
            ->method('deleteIndex')
            ->with('test_index')
            ->willReturn($responseMock);

        $engine = $this->createEngine();

        self::assertTrue($engine->indexDelete('test_index'));
    }

    // -----------------------------------------------------------------------
    // indexCommit
    // -----------------------------------------------------------------------

    /**
     * Tests that indexCommit() always returns true, since Algolia
     * automatically commits changes.
     */
    #[Test]
    public function indexCommitReturnsTrue(): void
    {
        $engine = $this->createEngine();

        self::assertTrue($engine->indexCommit());
    }

    // -----------------------------------------------------------------------
    // indexClear
    // -----------------------------------------------------------------------

    /**
     * Tests that indexClear() calls clearObjects() on the client and returns
     * the validation result from the UpdatedAtResponse.
     */
    #[Test]
    public function indexClearCallsClientAndReturnsResult(): void
    {
        $responseMock = $this->createMock(UpdatedAtResponse::class);
        $responseMock
            ->method('valid')
            ->willReturn(true);

        $this->searchClientMock
            ->expects(self::once())
            ->method('clearObjects')
            ->with('test_index')
            ->willReturn($responseMock);

        $engine = $this->createEngine();

        self::assertTrue($engine->indexClear('test_index'));
    }

    /**
     * Tests that indexClear() throws a RateLimitException when the Algolia
     * client responds with HTTP status code 429.
     */
    #[Test]
    public function indexClearThrowsRateLimitExceptionOn429(): void
    {
        $this->searchClientMock
            ->method('clearObjects')
            ->willThrowException(new Exception('Rate limit exceeded', 429));

        $engine = $this->createEngine();

        $this->expectException(RateLimitException::class);

        $engine->indexClear('test_index');
    }

    /**
     * Tests that indexClear() returns false when a non-rate-limit exception
     * (e.g. HTTP 500) is thrown by the client.
     */
    #[Test]
    public function indexClearReturnsFalseOnOtherException(): void
    {
        $this->searchClientMock
            ->method('clearObjects')
            ->willThrowException(new Exception('Some error', 500));

        $engine = $this->createEngine();

        self::assertFalse($engine->indexClear('test_index'));
    }

    // -----------------------------------------------------------------------
    // documentAdd
    // -----------------------------------------------------------------------

    /**
     * Tests that documentAdd() throws a RuntimeException when called
     * without opening an index first.
     */
    #[Test]
    public function documentAddThrowsExceptionWhenNoIndexOpen(): void
    {
        $engine = $this->createEngine();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index name not set');

        $engine->documentAdd($this->createDocumentMock());
    }

    /**
     * Tests the full documentAdd() happy path: generates a unique objectID
     * via the event dispatcher and saves the document fields to Algolia.
     */
    #[Test]
    public function documentAddSavesObjectToAlgolia(): void
    {
        $this->configureEventDispatcher('pages-42');

        $responseMock = $this->createMock(SaveObjectResponse::class);
        $responseMock
            ->method('valid')
            ->willReturn(true);

        $this->searchClientMock
            ->expects(self::once())
            ->method('saveObject')
            ->with(
                'test_index',
                self::callback(static fn (array $fields): bool => isset($fields['objectID'])
                    && $fields['objectID'] === 'pages-42'
                    && $fields['title'] === 'Test')
            )
            ->willReturn($responseMock);

        $engine = $this->createEngine();
        $engine->indexOpen('test_index');

        self::assertTrue($engine->documentAdd($this->createDocumentMock()));
    }

    // -----------------------------------------------------------------------
    // documentUpdate
    // -----------------------------------------------------------------------

    /**
     * Tests that documentUpdate() delegates to documentAdd(), resulting
     * in a single saveObject() call to the Algolia client.
     */
    #[Test]
    public function documentUpdateDelegatesToDocumentAdd(): void
    {
        $this->configureEventDispatcher();

        $responseMock = $this->createMock(SaveObjectResponse::class);
        $responseMock
            ->method('valid')
            ->willReturn(true);

        $this->searchClientMock
            ->expects(self::once())
            ->method('saveObject')
            ->willReturn($responseMock);

        $engine = $this->createEngine();
        $engine->indexOpen('test_index');

        self::assertTrue($engine->documentUpdate($this->createDocumentMock()));
    }

    // -----------------------------------------------------------------------
    // documentDeleteMany / deleteManyFromIndex
    // -----------------------------------------------------------------------

    /**
     * Tests that documentDeleteMany() throws a RuntimeException when called
     * without opening an index first.
     */
    #[Test]
    public function documentDeleteManyThrowsExceptionWhenNoIndexOpen(): void
    {
        $engine = $this->createEngine();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index name not set');

        $engine->documentDeleteMany(['doc-1']);
    }

    /**
     * Tests that documentDeleteMany() sends all document IDs in one call to
     * deleteObjects() of the client.
     */
    #[Test]
    public function documentDeleteManyCallsTheBatchDeleteOfTheClient(): void
    {
        $this->searchClientMock
            ->expects(self::once())
            ->method('deleteObjects')
            ->with('test_index', ['doc-1', 'doc-2'])
            ->willReturn([['taskID' => 1]]);

        $engine = $this->createEngine();
        $engine->indexOpen('test_index');

        $engine->documentDeleteMany(['doc-1', 'doc-2']);
    }

    /**
     * Tests that deleteManyFromIndex() builds the document ID of every record
     * and removes them from the index with one batch call.
     */
    #[Test]
    public function deleteManyFromIndexRemovesTheDocumentsOfAllRecordsInOneCall(): void
    {
        $this->eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static function (CreateUniqueDocumentIdEvent $event): CreateUniqueDocumentIdEvent {
                    $event->setDocumentId('ext:' . $event->getTableName() . ':' . $event->getRecordUid());

                    return $event;
                }
            );

        $this->searchClientMock
            ->expects(self::once())
            ->method('deleteObjects')
            ->with('test_index', ['ext:pages:4', 'ext:pages:7'])
            ->willReturn([['taskID' => 1]]);

        $engine = $this->createEngine()->withIndexName('test_index');
        $engine->deleteManyFromIndex('pages', [4, 7, 4]);
    }

    /**
     * Tests that deleteManyFromIndex() opens the index, removes the documents,
     * commits and closes the index again, in that order, and does nothing at
     * all for an empty list.
     */
    #[Test]
    public function deleteManyFromIndexOpensRemovesCommitsAndClosesInThatOrder(): void
    {
        $this->eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static function (CreateUniqueDocumentIdEvent $event): CreateUniqueDocumentIdEvent {
                    $event->setDocumentId('ext:' . $event->getTableName() . ':' . $event->getRecordUid());

                    return $event;
                }
            );

        $calls = [];

        $engine = $this->getMockBuilder(AlgoliaSearchEngine::class)
            ->setConstructorArgs([
                $this->eventDispatcherMock,
                $this->extensionConfigMock,
                $this->searchClientFactoryMock,
            ])
            ->onlyMethods(['indexOpen', 'documentDeleteMany', 'indexCommit', 'indexClose'])
            ->getMock();

        $engine
            ->method('indexOpen')
            ->willReturnCallback(
                static function (string $indexName) use (&$calls): void {
                    $calls[] = 'open ' . $indexName;
                }
            );
        $engine
            ->method('documentDeleteMany')
            ->willReturnCallback(
                static function (array $documentIds) use (&$calls): bool {
                    $calls[] = 'delete ' . implode(',', $documentIds);

                    return true;
                }
            );
        $engine
            ->method('indexCommit')
            ->willReturnCallback(
                static function () use (&$calls): bool {
                    $calls[] = 'commit';

                    return true;
                }
            );
        $engine
            ->method('indexClose')
            ->willReturnCallback(
                static function () use (&$calls): void {
                    $calls[] = 'close';
                }
            );

        $engine->withIndexName('test_index')->deleteManyFromIndex('pages', []);

        self::assertSame([], $calls, 'Nothing is done for an empty list');

        $engine->withIndexName('test_index')->deleteManyFromIndex('pages', [4, 7]);

        self::assertSame(
            ['open test_index', 'delete ext:pages:4,ext:pages:7', 'commit', 'close'],
            $calls
        );
    }

    /**
     * Tests that deleteManyFromIndex() closes the index again when the engine
     * fails to remove the documents, and does not commit what failed.
     */
    #[Test]
    public function deleteManyFromIndexClosesTheIndexWhenTheRemovalFails(): void
    {
        $this->eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static function (CreateUniqueDocumentIdEvent $event): CreateUniqueDocumentIdEvent {
                    $event->setDocumentId('ext:' . $event->getRecordUid());

                    return $event;
                }
            );

        $engine = $this->getMockBuilder(AlgoliaSearchEngine::class)
            ->setConstructorArgs([
                $this->eventDispatcherMock,
                $this->extensionConfigMock,
                $this->searchClientFactoryMock,
            ])
            ->onlyMethods(['indexOpen', 'documentDeleteMany', 'indexCommit', 'indexClose'])
            ->getMock();

        $engine
            ->method('documentDeleteMany')
            ->willThrowException(new RuntimeException('Algolia is not reachable'));
        $engine
            ->expects(self::never())
            ->method('indexCommit');
        $engine
            ->expects(self::once())
            ->method('indexClose');

        $this->expectException(RuntimeException::class);

        $engine->withIndexName('test_index')->deleteManyFromIndex('pages', [4]);
    }

    /**
     * Tests that deleteManyFromIndex() does not close an index that it could not
     * open, so the failure of the open is the one that is seen.
     */
    #[Test]
    public function deleteManyFromIndexDoesNotCloseAnIndexThatCouldNotBeOpened(): void
    {
        $this->eventDispatcherMock
            ->method('dispatch')
            ->willReturnCallback(
                static function (CreateUniqueDocumentIdEvent $event): CreateUniqueDocumentIdEvent {
                    $event->setDocumentId('ext:' . $event->getRecordUid());

                    return $event;
                }
            );

        $engine = $this->getMockBuilder(AlgoliaSearchEngine::class)
            ->setConstructorArgs([
                $this->eventDispatcherMock,
                $this->extensionConfigMock,
                $this->searchClientFactoryMock,
            ])
            ->onlyMethods(['indexOpen', 'indexClose'])
            ->getMock();

        $engine
            ->method('indexOpen')
            ->willThrowException(new RuntimeException('Index does not exist'));
        $engine
            ->expects(self::never())
            ->method('indexClose');

        $this->expectException(RuntimeException::class);

        $engine->withIndexName('test_index')->deleteManyFromIndex('pages', [4]);
    }

    /**
     * Tests that deleteManyFromIndex() makes no request for an empty list.
     */
    #[Test]
    public function deleteManyFromIndexMakesNoRequestForNoRecords(): void
    {
        $this->searchClientMock
            ->expects(self::never())
            ->method('deleteObjects');

        $engine = $this->createEngine()->withIndexName('test_index');
        $engine->deleteManyFromIndex('pages', []);
    }

    /**
     * Tests that deleteManyFromIndex() requires an index name like the
     * deletion of a single record.
     */
    #[Test]
    public function deleteManyFromIndexThrowsExceptionWithoutIndexName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('Index name not set', '/') . '/');

        $this->createEngine()->deleteManyFromIndex('pages', [4]);
    }
}
