<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Builder;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\ContentRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Unit tests for DocumentBuilder.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(DocumentBuilder::class)]
#[UsesClass(ContentExtractor::class)]
#[UsesClass(AfterDocumentAssembledEvent::class)]
#[UsesClass(Document::class)]
#[UsesClass(ContentRepository::class)]
#[UsesClass(PageRepository::class)]
#[UsesClass(TypoScriptService::class)]
class DocumentBuilderTest extends TestCase
{
    /**
     * @var MockObject&EventDispatcherInterface
     */
    private MockObject $eventDispatcherMock;

    /**
     * @var MockObject&ConfigurationManagerInterface
     */
    private MockObject $configurationManagerMock;

    private DocumentBuilder $builder;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcherMock      = $this->createMock(EventDispatcherInterface::class);
        $this->configurationManagerMock = $this->createMock(ConfigurationManagerInterface::class);

        $typoScriptService = new TypoScriptService($this->configurationManagerMock);

        $this->builder = new DocumentBuilder(
            $this->eventDispatcherMock,
            $typoScriptService
        );
    }

    /**
     * Tests that setIndexer() returns the builder instance itself, enabling fluent
     * method chaining. Verifies the fluent interface pattern is correctly implemented.
     */
    #[Test]
    public function setIndexerReturnsSelf(): void
    {
        $indexerMock = $this->createMock(IndexerInterface::class);

        self::assertSame($this->builder, $this->builder->setIndexer($indexerMock));
    }

    /**
     * Tests that setRecord() returns the builder instance itself, enabling fluent
     * method chaining. Verifies the fluent interface pattern is correctly implemented.
     */
    #[Test]
    public function setRecordReturnsSelf(): void
    {
        self::assertSame($this->builder, $this->builder->setRecord(['uid' => 1]));
    }

    /**
     * Tests that setIndexingService() returns the builder instance itself, enabling fluent
     * method chaining. Verifies the fluent interface pattern is correctly implemented.
     */
    #[Test]
    public function setIndexingServiceReturnsSelf(): void
    {
        $indexingServiceMock = $this->createMock(IndexingService::class);

        self::assertSame($this->builder, $this->builder->setIndexingService($indexingServiceMock));
    }

    /**
     * Tests that assemble() returns the builder instance without performing any assembly
     * when no indexer has been set. This is the guard clause at the beginning of assemble()
     * that checks for a valid IndexerInterface instance.
     */
    #[Test]
    public function assembleReturnsSelfWhenNoIndexerSet(): void
    {
        self::assertSame($this->builder, $this->builder->assemble());
    }

    /**
     * Tests that assemble() returns the builder instance without performing any assembly
     * when the indexer has been explicitly set to null. This verifies the guard clause
     * handles null indexers correctly.
     */
    #[Test]
    public function assembleReturnsSelfWhenIndexerSetToNull(): void
    {
        $this->builder->setIndexer(null);

        self::assertSame($this->builder, $this->builder->assemble());
    }

    /**
     * Tests that assemble() dispatches an AfterDocumentAssembledEvent after building the
     * document. This event allows other listeners to modify the document before indexing.
     * Verifies the event dispatcher is called exactly once with the correct event type.
     */
    #[Test]
    public function assembleDispatchesAfterDocumentAssembledEvent(): void
    {
        $this->configureTypoScriptForEmptyFieldMapping();

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->eventDispatcherMock
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(AfterDocumentAssembledEvent::class));

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord(['uid' => 1, 'pid' => 10])
            ->setIndexingService($indexingServiceMock)
            ->assemble();
    }

    /**
     * Tests that assemble() creates a document with the standard fields: uid, pid, type,
     * and indexed timestamp. These fields are always present regardless of TypoScript
     * configuration. The 'type' field contains the table name and 'indexed' is a Unix timestamp.
     */
    #[Test]
    public function assembleCreatesDocumentWithStandardFields(): void
    {
        $this->configureTypoScriptForEmptyFieldMapping();

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord(['uid' => 42, 'pid' => 10])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame(42, $document->getFields()['uid']);
        self::assertSame(10, $document->getFields()['pid']);
        self::assertSame('pages', $document->getFields()['type']);
        self::assertIsInt($document->getFields()['indexed']);
    }

    /**
     * Tests that assemble() includes the record creation timestamp in the document when
     * the TCA configuration defines a 'crdate' control field for the table. The creation
     * timestamp is mapped to the 'created' field in the document.
     */
    #[Test]
    public function assembleAddsCreatedTimestampFromTca(): void
    {
        $GLOBALS['TCA']['pages']['ctrl']['crdate'] = 'crdate';

        $this->configureTypoScriptForEmptyFieldMapping();

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord(['uid' => 1, 'pid' => 0, 'crdate' => 1700000000])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame(1700000000, $document->getFields()['created']);

        unset($GLOBALS['TCA']['pages']);
    }

    /**
     * Tests that assemble() includes the record modification timestamp in the document when
     * the TCA configuration defines a 'tstamp' control field for the table. The modification
     * timestamp is mapped to the 'changed' field in the document.
     */
    #[Test]
    public function assembleAddsChangedTimestampFromTca(): void
    {
        $GLOBALS['TCA']['pages']['ctrl']['tstamp'] = 'tstamp';

        $this->configureTypoScriptForEmptyFieldMapping();

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord(['uid' => 1, 'pid' => 0, 'tstamp' => 1700000001])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame(1700000001, $document->getFields()['changed']);

        unset($GLOBALS['TCA']['pages']);
    }

    /**
     * Tests that assemble() adds record fields to the document that are defined in the
     * TypoScript field mapping configuration. The field mapping maps record field names
     * to document field names under module.tx_typo3searchalgolia.indexer.<type>.fields.
     */
    #[Test]
    public function assembleAddsConfiguredFieldsFromTypoScript(): void
    {
        $this->configureTypoScriptForFieldMapping('pages', [
            'title'    => 'title',
            'subtitle' => 'subtitle',
        ]);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord([
                'uid'      => 1,
                'pid'      => 0,
                'title'    => 'Test Page',
                'subtitle' => 'A subtitle',
            ])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame('Test Page', $document->getFields()['title']);
        self::assertSame('A subtitle', $document->getFields()['subtitle']);
    }

    /**
     * Tests that assemble() skips record fields with empty string values when adding
     * configured fields from TypoScript. Empty values should not pollute the search
     * index with meaningless entries.
     */
    #[Test]
    public function assembleSkipsEmptyFieldValues(): void
    {
        $this->configureTypoScriptForFieldMapping('pages', [
            'title'    => 'title',
            'subtitle' => 'subtitle',
        ]);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord([
                'uid'      => 1,
                'pid'      => 0,
                'title'    => 'Test Page',
                'subtitle' => '',
            ])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame('Test Page', $document->getFields()['title']);
        self::assertArrayNotHasKey('subtitle', $document->getFields());
    }

    /**
     * Tests that assemble() skips record fields with non-scalar values (arrays, objects)
     * when adding configured fields from TypoScript. Only scalar values (strings, ints,
     * floats, booleans) can be meaningfully indexed.
     */
    #[Test]
    public function assembleSkipsNonScalarFieldValues(): void
    {
        $this->configureTypoScriptForFieldMapping('pages', [
            'title'  => 'title',
            'config' => 'config',
        ]);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord([
                'uid'    => 1,
                'pid'    => 0,
                'title'  => 'Test Page',
                'config' => ['nested' => 'array'],
            ])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame('Test Page', $document->getFields()['title']);
        self::assertArrayNotHasKey('config', $document->getFields());
    }

    /**
     * Tests that assemble() ignores record fields that are not present in the TypoScript
     * field mapping. Only explicitly configured fields should be added to the document,
     * preventing unintended data from being indexed.
     */
    #[Test]
    public function assembleSkipsFieldsNotInMapping(): void
    {
        $this->configureTypoScriptForFieldMapping('pages', [
            'title' => 'title',
        ]);

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord([
                'uid'         => 1,
                'pid'         => 0,
                'title'       => 'Test Page',
                'description' => 'Not mapped',
            ])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame('Test Page', $document->getFields()['title']);
        self::assertArrayNotHasKey('description', $document->getFields());
    }

    /**
     * Tests that assemble() defaults the 'pid' field to zero when the record does not
     * contain a 'pid' key. This ensures the document always has a valid pid value,
     * even for records at the root level.
     */
    #[Test]
    public function assembleUsesPidZeroWhenPidNotInRecord(): void
    {
        $this->configureTypoScriptForEmptyFieldMapping();

        $indexerMock = $this->createMock(IndexerInterface::class);
        $indexerMock
            ->method('getTable')
            ->willReturn('pages');

        $indexingServiceMock = $this->createMock(IndexingService::class);

        $this->builder
            ->setIndexer($indexerMock)
            ->setRecord(['uid' => 1])
            ->setIndexingService($indexingServiceMock)
            ->assemble();

        $document = $this->builder->getDocument();

        self::assertSame(0, $document->getFields()['pid']);
    }

    /**
     * Configures the ConfigurationManagerInterface mock to return a TypoScript configuration
     * with no field mappings. The resulting configuration contains the required module structure
     * but without any indexer field definitions.
     */
    private function configureTypoScriptForEmptyFieldMapping(): void
    {
        $this->configurationManagerMock
            ->method('getConfiguration')
            ->willReturn([
                'module.' => [
                    'tx_typo3searchalgolia.' => [],
                ],
            ]);
    }

    /**
     * Configures the ConfigurationManagerInterface mock to return a TypoScript configuration
     * with field mappings for the given indexer type. The configuration uses the TypoScript
     * dot notation which gets processed by GeneralUtility::removeDotsFromTS().
     *
     * @param string                $type   The indexer type (e.g. 'pages', 'tt_content')
     * @param array<string, string> $fields The field mapping (record field name => document field name)
     */
    private function configureTypoScriptForFieldMapping(string $type, array $fields): void
    {
        $this->configurationManagerMock
            ->method('getConfiguration')
            ->willReturn([
                'module.' => [
                    'tx_typo3searchalgolia.' => [
                        'indexer.' => [
                            $type . '.' => [
                                'fields.' => $fields,
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
