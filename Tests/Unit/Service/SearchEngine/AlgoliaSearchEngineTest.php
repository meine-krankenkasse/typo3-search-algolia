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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine\AlgoliaSearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngine\SearchClientFactoryInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Unit tests for AlgoliaSearchEngine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AlgoliaSearchEngine::class)]
class AlgoliaSearchEngineTest extends TestCase
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

    #[Test]
    public function indexCommitReturnsTrue(): void
    {
        $engine = $this->createEngine();

        self::assertTrue($engine->indexCommit());
    }

    // -----------------------------------------------------------------------
    // indexClear
    // -----------------------------------------------------------------------

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

    #[Test]
    public function documentAddThrowsExceptionWhenNoIndexOpen(): void
    {
        $engine = $this->createEngine();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index name not set');

        $engine->documentAdd($this->createDocumentMock());
    }

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
    // documentDelete
    // -----------------------------------------------------------------------

    #[Test]
    public function documentDeleteThrowsExceptionWhenNoIndexOpen(): void
    {
        $engine = $this->createEngine();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index name not set');

        $engine->documentDelete('doc-1');
    }

    #[Test]
    public function documentDeleteCallsClientAndReturnsResult(): void
    {
        $responseMock = $this->createMock(DeletedAtResponse::class);
        $responseMock
            ->method('valid')
            ->willReturn(true);

        $this->searchClientMock
            ->expects(self::once())
            ->method('deleteObject')
            ->with('test_index', 'doc-1')
            ->willReturn($responseMock);

        $engine = $this->createEngine();
        $engine->indexOpen('test_index');

        self::assertTrue($engine->documentDelete('doc-1'));
    }
}
