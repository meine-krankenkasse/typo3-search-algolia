<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Controller;

use DateTimeImmutable;
use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Controller\AttributeOverviewModuleController;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginResolverInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\NewsIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\AbstractDelegatingDocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\ArrayFieldInjectingDocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\AttributeOverviewModuleControllerTestSubject;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\LimitCapture;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\LimitCapturingIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\LimitCapturingIndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\NullReturningIndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\ObjectFieldInjectingDocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\PhantomAttributeInjectingOriginResolver;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\PhantomRecordUidIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\PhantomRecordUidIndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\StringFieldInjectingDocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\ThrowingForTableDocumentBuilder;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

use function file_get_contents;
use function preg_quote;
use function strpos;
use function substr_count;

/**
 * Functional tests for AttributeOverviewModuleController.
 *
 * As a functional test, this drives a real, DI-resolved controller against
 * real CSV fixtures loaded into a real (SQLite) test database, so the real
 * in-extension AfterDocumentAssembledEvent listeners
 * (UpdateAssembledPageDocumentEventListener,
 * UpdateAssembledContentElementDocumentEventListener) fire for real during
 * DocumentBuilder::assemble() - nothing about origin classification or the
 * cross-table aggregation is mocked.
 *
 * This class replaces an earlier version built entirely around a per-table
 * record-selector dropdown plus a separate cross-table "schema gap"
 * comparison (SchemaGapDetector), both dropped in the WEB-1351 Option B
 * redesign: the schema-gap comparison produced permanent, unfixable noise
 * for record types that are legitimately different by design, and the
 * per-record dropdown was over-engineered for the approved audience
 * (external TER adopters reading a documentation-oriented overview, not
 * day-to-day editors driving an interactive diagnostic tool).
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AttributeOverviewModuleController::class)]
#[UsesClass(AttributeOverviewModuleControllerTestSubject::class)]
#[UsesClass(AbstractDelegatingDocumentBuilder::class)]
#[UsesClass(ArrayFieldInjectingDocumentBuilder::class)]
#[UsesClass(LimitCapture::class)]
#[UsesClass(LimitCapturingIndexer::class)]
#[UsesClass(LimitCapturingIndexerFactory::class)]
#[UsesClass(NullReturningIndexerFactory::class)]
#[UsesClass(ObjectFieldInjectingDocumentBuilder::class)]
#[UsesClass(PhantomAttributeInjectingOriginResolver::class)]
#[UsesClass(PhantomRecordUidIndexer::class)]
#[UsesClass(PhantomRecordUidIndexerFactory::class)]
#[UsesClass(StringFieldInjectingDocumentBuilder::class)]
#[UsesClass(ThrowingForTableDocumentBuilder::class)]
final class AttributeOverviewModuleControllerTest extends AbstractFunctionalTestCase
{
    /**
     * The Local storage driver lists files by scanning the real filesystem
     * under the test instance's fileadmin folder, sys_file DB rows alone
     * are not enough to make FolderBasedFileCollection::loadContents()
     * (used by FileIndexer::initQueueItemRecords() to resolve real File
     * objects, not FileReference objects) find a file. Only
     * attributeOverviewSuppressesTheStatusSectionWhenEveryRecordTypeHasPreviewData()
     * needs this, but pathsToProvideInTestInstance is a class-wide property
     * of the testing framework, providing one unused file is harmless to
     * every other test in this class.
     *
     * @var array<string, non-empty-string>
     */
    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/typo3_search_algolia/Tests/Functional/Fixtures/Files/test.pdf' => 'fileadmin/test.pdf',
    ];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // ModuleTemplate reads $GLOBALS['LANG'] directly and
        // ModuleTemplate::prepareRender() reads $GLOBALS['BE_USER'], neither
        // of which a plain functional test bootstrap populates on its own -
        // mirrors AbstractBaseModuleControllerTest's setUp() exactly.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/be_users.csv');
        $this->setUpBackendUser(1);

        // The news extension is not a hard dependency of this extension, so
        // its table/TCA do not exist by default in this test bootstrap.
        // Reuses the exact setup NewsIndexerTest already establishes for the
        // same reason (see that test's setUp() for the full rationale of
        // each TCA key).
        $sql = file_get_contents(__DIR__ . '/../Fixtures/Database/create_tx_news_domain_model_news.sql');
        self::assertIsString($sql);

        $this->getConnectionPool()
            ->getConnectionByName('Default')
            ->executeStatement($sql);

        $GLOBALS['TCA']['tx_news_domain_model_news'] = [
            'ctrl' => [
                'tstamp'        => 'tstamp',
                'crdate'        => 'crdate',
                'delete'        => 'deleted',
                'enablecolumns' => [
                    'disabled'  => 'hidden',
                    'starttime' => 'starttime',
                    'endtime'   => 'endtime',
                ],
                'languageField'            => 'sys_language_uid',
                'transOrigPointerField'    => 'l10n_parent',
                'transOrigDiffSourceField' => 'l10n_diffsource',
            ],
            'columns' => [
                'hidden' => [
                    'label'  => 'Hidden',
                    'config' => [
                        'type' => 'check',
                    ],
                ],
                'starttime' => [
                    'label'  => 'Start time',
                    'config' => [
                        'type' => 'datetime',
                    ],
                ],
                'endtime' => [
                    'label'  => 'End time',
                    'config' => [
                        'type' => 'datetime',
                    ],
                ],
            ],
        ];

        $this->get(TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);

        // AttributeOverviewModuleController::getRecordTypes() derives the
        // module's record-type list live from IndexerRegistry, the same
        // registry ext_localconf.php populates itself, but only registers
        // NewsIndexer there if (ExtensionManagementUtility::isLoaded('news')).
        // Since EXT:news is not a hard dependency, that condition is false
        // in this test bootstrap and the real ext_localconf.php never runs
        // this registration. Reproduce it manually here, mirroring the exact
        // registration ext_localconf.php performs, registered LAST (after
        // pages/tt_content/sys_file_metadata, which ext_localconf.php itself
        // already registered when the container booted), matching real
        // production registration order and, with it, this module's own
        // getRecordTypes() order.
        IndexerRegistry::register(
            NewsIndexer::class,
            NewsIndexer::TABLE,
            'News',
        );
    }

    /**
     * Manually wires the test subject from real, container-resolved
     * collaborators rather than fetching the class itself from the
     * container - AttributeOverviewModuleController (like other
     * ActionController-derived module controllers in this extension) is
     * not registered as a public service, mirroring
     * AbstractBaseModuleControllerTest::createSubject()'s same approach
     * for its own (smaller) test subject.
     */
    private function createSubject(
        ?DocumentBuilder $documentBuilder = null,
        ?IndexerFactory $indexerFactory = null,
        ?AttributeOriginResolverInterface $attributeOriginResolver = null,
    ): AttributeOverviewModuleControllerTestSubject {
        return new AttributeOverviewModuleControllerTestSubject(
            $this->get(ModuleTemplateFactory::class),
            $this->get(IconFactory::class),
            $this->get(IndexingServiceRepository::class),
            $this->get(ConnectionPool::class),
            $indexerFactory ?? $this->get(IndexerFactory::class),
            $documentBuilder ?? $this->get(DocumentBuilder::class),
            $attributeOriginResolver ?? $this->get(AttributeOriginResolverInterface::class),
        );
    }

    /**
     * Builds a real Extbase request wrapping a real PSR-7 ServerRequest,
     * carrying every attribute AttributeOverviewModuleController::indexAction(),
     * ModuleTemplateFactory::create() and BackendViewFactory (via the route's
     * packageName) need - mirrors
     * AbstractBaseModuleControllerTest::createModuleRequest(), but returns a
     * real TYPO3\CMS\Extbase\Mvc\Request decorator instead of a stub, since
     * that class requires a real 'extbase' attribute on construction anyway
     * and a real request keeps this test's "real fixtures, real controller"
     * character consistent through to the request object itself.
     *
     * @param array<string, mixed> $queryParams
     */
    private function createModuleRequest(array $queryParams): RequestInterface
    {
        $route = new Route(
            '/module/typo3-search-algolia/attributes',
            [
                'packageName' => 'meine-krankenkasse/typo3-search-algolia',
            ],
        );

        $extbaseRequestParameters = (new ExtbaseRequestParameters())
            ->setControllerExtensionName('Typo3SearchAlgolia')
            ->setControllerName('AttributeOverviewModule')
            ->setControllerActionName('index');

        $serverRequest = (new ServerRequest())
            ->withAttribute(
                'route',
                $route,
            )
            ->withAttribute(
                'extbase',
                $extbaseRequestParameters,
            )
            ->withAttribute(
                'applicationType',
                SystemEnvironmentBuilder::REQUESTTYPE_BE,
            )
            ->withAttribute(
                'normalizedParams',
                NormalizedParams::createFromServerParams($_SERVER),
            )
            ->withQueryParams($queryParams);

        return new Request($serverRequest);
    }

    /**
     * Matches $pattern against $body and asserts it matched exactly once,
     * so every extract*Html() helper below shares one match+assert+return
     * skeleton and only supplies its own pattern and failure message.
     */
    private function assertSingleMatch(string $pattern, string $body, string $message): string
    {
        $matchCount = preg_match_all(
            $pattern,
            $body,
            $matches,
        );

        self::assertSame(
            1,
            $matchCount,
            $message,
        );

        return $matches[0][0];
    }

    /**
     * Extracts one attribute's own rendered <tbody> group from the flat
     * overview table, so an assertion can be scoped to that one attribute
     * and not accidentally match content belonging to a different attribute
     * or to the table-status list above it. Each attribute gets its own
     * <tbody data-attribute="..."> (see Index.html), holding one <tr> per
     * table it occurs on, with only the first row carrying the rowspanned
     * attribute-name/example-value cells.
     */
    private function extractAttributeRowHtml(string $body, string $attributeName): string
    {
        return $this->assertSingleMatch(
            '#<tbody data-attribute="' . preg_quote(
                $attributeName,
                '#',
            ) . '">.*?</tbody>#s',
            $body,
            'Expected exactly one rendered row group for attribute "' . $attributeName . '".',
        );
    }

    /**
     * Extracts one table's own status line from the table-status list, so
     * an assertion can be scoped to that one table's status message.
     */
    private function extractStatusLineHtml(string $body, string $table): string
    {
        return $this->assertSingleMatch(
            '#<li>\s*<code>' . preg_quote(
                $table,
                '#',
            ) . '</code>:.*?</li>#s',
            $body,
            'Expected exactly one status line for table "' . $table . '".',
        );
    }

    /**
     * Extracts the flat attribute table's own markup (excluding the
     * table-status list above it), so a "must NOT mention this table"
     * assertion cannot accidentally match that table's name appearing in
     * its OWN status line instead.
     */
    private function extractAttributeTableHtml(string $body): string
    {
        return $this->assertSingleMatch(
            '#<table class="table attribute-overview-table mt-4">.*?</table>#s',
            $body,
            'Expected exactly one rendered attribute table.',
        );
    }

    /**
     * Asserts $value is rendered as the row group's rowspanned example-value
     * cell content, regardless of the exact rowspan count (which varies with
     * how many tables the attribute occurs on, see Index.html).
     */
    private function assertExampleValueCellContains(string $value, string $rowHtml, string $message = ''): void
    {
        self::assertMatchesRegularExpression(
            '#<td rowspan="\d+">' . preg_quote(
                $value,
                '#',
            ) . '</td>#',
            $rowHtml,
            $message,
        );
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function createDrivenSubject(
        array $queryParams,
        ?DocumentBuilder $documentBuilder = null,
        ?IndexerFactory $indexerFactory = null,
        ?AttributeOriginResolverInterface $attributeOriginResolver = null,
    ): AttributeOverviewModuleControllerTestSubject {
        $request = $this->createModuleRequest($queryParams);

        $subject = $this->createSubject(
            $documentBuilder,
            $indexerFactory,
            $attributeOriginResolver,
        );
        $subject->setRequestForTest($request);
        $subject->setModuleTemplateForTest(
            $this->get(ModuleTemplateFactory::class)->create($request),
        );

        return $subject;
    }

    /**
     * Calls the index action and asserts the response succeeded, so every
     * call site can assert on the response body without repeating the
     * status-code check.
     */
    private function callIndexActionAndAssertOk(AttributeOverviewModuleControllerTestSubject $subject): string
    {
        $response = $subject->callIndexAction();

        self::assertSame(
            200,
            $response->getStatusCode(),
        );

        return (string) $response->getBody();
    }

    /**
     * Verifies the core aggregation mechanism: 'site' is set by both
     * UpdateAssembledPageDocumentEventListener and
     * UpdateAssembledContentElementDocumentEventListener (Classes/EventListener/),
     * so its row must show exactly two occurrences (pages, tt_content), both
     * Listener-origin, and must NOT mention tx_news_domain_model_news, which
     * has no equivalent listener. Also checks the 'uid' row carries a
     * Default-origin badge, proving the resolver ran and its result reached
     * the template for the module's always-present default field, not just
     * for listener-classified ones.
     *
     * Additionally proves a row can aggregate 3+ occurrences, which the
     * 'site' assertions above cannot: 'site' is only set on pages/tt_content
     * (News has no listener setting it, see Classes/EventListener/), so a
     * bug in mergeTableAttributes() that only ever accumulates up to two
     * occurrences (e.g. an overwrite instead of an accumulate) would go
     * unnoticed there. 'title' IS TypoScript-mapped on all three imported
     * tables (Configuration/TypoScript/setup.typoscript: pages.title,
     * tt_content.header, tx_news_domain_model_news.title, all targeting
     * 'title'), so its row must show exactly three occurrences, each
     * TypoScript-origin, each with its own, table-specific TypoScript path
     * reflecting the differing source field name (title vs. header vs.
     * title).
     *
     * Also pins the "Record types checked: ..." line's full record-type set
     * and registration order (pages, tt_content, sys_file_metadata,
     * tx_news_domain_model_news), reusing this same fixture set.
     */
    #[Test]
    public function attributeOverviewAggregatesAnAttributePresentOnMultipleTablesIntoOneRowWithMultipleOccurrences(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_news_domain_model_news.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        // Regression: the h2 used to read <f:translate key="mod_attributes" />,
        // a key that only exists in locallang_mod_search.xlf (used for the
        // module's registration title, resolved via an explicit LLL: path in
        // Modules.php). A bare f:translate key resolves against locallang.xlf
        // instead, so that key rendered as an empty heading. Revert-confirms-
        // red verified: reverting Index.html's h2 key back to "mod_attributes"
        // makes this assertion fail.
        self::assertStringContainsString(
            '<h2>Attributes</h2>',
            $body,
            'The module heading must render real text, not resolve to an empty translation key.',
        );

        // Same "renders but nobody checks it" risk class the h2 regression
        // above came from: this table has no test coverage at all for its
        // own static headings/headers, none of which are otherwise pinned
        // by data-driven assertions elsewhere in this test class.
        self::assertStringContainsString(
            '<h3 class="mb-3">Record types without preview data</h3>',
            $body,
            'The table-status section heading must render real text.',
        );

        self::assertStringContainsString(
            '<th>Attribute</th>',
            $body,
            'The attribute-table column headers must render real text, not an unresolved translation key.',
        );
        self::assertStringContainsString(
            '<th>Origin</th>',
            $body,
        );
        self::assertStringContainsString(
            '<th>Occurs on</th>',
            $body,
        );
        self::assertStringContainsString(
            '<th>Path</th>',
            $body,
        );
        self::assertStringContainsString(
            '<th>Example value</th>',
            $body,
        );

        // The five assertStringContainsString() calls above only pin
        // presence, not relative order - a swapped <th>Origin</th> /
        // <th>Occurs on</th> pair (headers pointing at the wrong data
        // beneath them) would still pass all five. Pin the actual header
        // sequence too. Revert-confirms-red verified: swapping the Origin
        // and Occurs on <th> elements in Index.html makes this fail.
        self::assertMatchesRegularExpression(
            '#<th>Attribute</th>\s*<th>Origin</th>\s*<th>Occurs on</th>\s*<th>Path</th>\s*'
            . '<th>Example value</th>#',
            $body,
            'The attribute-table column headers must render in Attribute, Origin, Occurs on, Path, '
            . 'Example value order, matching the data cells beneath them.',
        );

        self::assertMatchesRegularExpression(
            '#Record types checked:.*<code>pages</code>,\s*<code>tt_content</code>,\s*'
            . '<code>sys_file_metadata</code>,\s*<code>tx_news_domain_model_news</code>#s',
            $body,
            'The "Record types checked" line must name every table this module actually compares '
            . '(all 4 registered indexers, including sys_file_metadata even without an indexing '
            . "service configured for it in this test), in registration order, so a table's absence "
            . 'from a row\'s "occurs on" column stays positively legible against a stated, closed '
            . 'universe of tables.',
        );

        // The regex above is not end-anchored, so it alone cannot catch an
        // inverted f:if(condition: typeIter.isLast) in Index.html appending
        // a trailing separator after the LAST record type. Revert-confirms-
        // red verified: inverting that condition makes this assertion fail.
        self::assertStringNotContainsString(
            '<code>tx_news_domain_model_news</code>,',
            $body,
            'The last record type in the "Record types checked" line must not be followed by a '
            . 'trailing separator.',
        );

        // Pins ksort($attributeRows)'s alphabetical-sort contract: the
        // three <tbody data-attribute="..."> groups checked below must
        // appear in that exact relative order in the response body, not
        // registration/assembly-insertion order (which would put 'site'
        // last, since it's Listener-set only after the TypoScript-mapped
        // fields are assembled). Revert-confirms-red verified: removing
        // ksort() (or replacing it with krsort()) makes this assertion
        // fail.
        $sitePosition  = strpos($body, 'data-attribute="site"');
        $titlePosition = strpos($body, 'data-attribute="title"');
        $uidPosition   = strpos($body, 'data-attribute="uid"');

        self::assertNotFalse($sitePosition);
        self::assertNotFalse($titlePosition);
        self::assertNotFalse($uidPosition);
        self::assertLessThan(
            $titlePosition,
            $sitePosition,
            "The 'site' row must be rendered before the 'title' row (alphabetical order).",
        );
        self::assertLessThan(
            $uidPosition,
            $titlePosition,
            "The 'title' row must be rendered before the 'uid' row (alphabetical order).",
        );

        $siteRowHtml = $this->extractAttributeRowHtml(
            $body,
            'site',
        );

        self::assertStringContainsString(
            '<code>pages</code>',
            $siteRowHtml,
        );
        self::assertStringContainsString(
            '<code>tt_content</code>',
            $siteRowHtml,
        );
        self::assertStringNotContainsString(
            '<code>tx_news_domain_model_news</code>',
            $siteRowHtml,
        );

        self::assertSame(
            2,
            substr_count(
                $siteRowHtml,
                '<span class="badge">listener</span>',
            ),
            'The "site" row must show exactly two Listener-origin occurrences (pages, tt_content).',
        );

        // Guards the opposite direction of the Path-column detail
        // assertions elsewhere in this test (e.g. the TypoScript-origin
        // "title" row): Index.html's <f:if condition="{occurrence.detail}">
        // around the Path column's <code> element must suppress it
        // entirely for a Listener-origin occurrence, whose detail is
        // always NULL, leaving that cell empty, not an empty <code></code>.
        // Checking for the literal empty tag (not a content-specific
        // substring like "module.") is what actually discriminates this:
        // a NULL {occurrence.detail} interpolated into an unguarded
        // <code>{occurrence.detail}</code> renders exactly "<code></code>",
        // with no "module." text to catch. Revert-confirms-red verified:
        // removing that f:if condition makes this assertion fail.
        self::assertStringNotContainsString(
            '<code></code>',
            $siteRowHtml,
            'A Listener-origin occurrence has no detail and must not render an empty Path column <code> element.',
        );

        $uidRowHtml = $this->extractAttributeRowHtml(
            $body,
            'uid',
        );

        self::assertStringContainsString(
            '<span class="badge">default</span>',
            $uidRowHtml,
        );

        // Pins formatExampleValue()'s is_scalar() branch for a non-string
        // scalar: 'uid' is always an int (DocumentBuilder::assemble()'s
        // setField('uid', $this->record['uid'])), never a string, so this
        // is the only assertion in the file that ever exercises that
        // branch's actual rendered content - every other
        // assertExampleValueCellContains() call site targets a string
        // value. Revert-confirms-red verified: narrowing is_scalar($value)
        // to is_string($value) in formatExampleValue() leaves the rest of
        // this file green but makes this assertion fail (the cell renders
        // '' instead of the page uid).
        $this->assertExampleValueCellContains(
            '3',
            $uidRowHtml,
            "The 'uid' row's example value must render the auto-picked page's actual int uid (cast to "
            . 'string), not an empty value from a formatter that only handles strings.',
        );

        $titleRowHtml = $this->extractAttributeRowHtml(
            $body,
            'title',
        );

        self::assertSame(
            3,
            substr_count(
                $titleRowHtml,
                '<span class="badge">typoscript</span>',
            ),
            'The "title" row must show exactly three TypoScript-origin occurrences (pages, tt_content, '
            . 'tx_news_domain_model_news), proving a row can aggregate more than two occurrences.',
        );
        self::assertStringContainsString(
            '<code>module.tx_typo3searchalgolia.indexer.pages.fields.title</code>',
            $titleRowHtml,
        );
        self::assertStringContainsString(
            '<code>module.tx_typo3searchalgolia.indexer.tt_content.fields.header</code>',
            $titleRowHtml,
        );
        self::assertStringContainsString(
            '<code>module.tx_typo3searchalgolia.indexer.tx_news_domain_model_news.fields.title</code>',
            $titleRowHtml,
        );

        // Pins the rowspan wiring itself, not just its tolerant presence
        // (assertExampleValueCellContains() deliberately wildcards the
        // count via "rowspan=\d+", since it varies per attribute): with
        // 3 occurrences, {occurrenceIterator.total} must render "3", and
        // the rowspanned attribute-name/example-value cells must appear
        // exactly once each (not once per <tr>), never duplicated onto
        // the continuation rows. Revert-confirms-red verified: removing
        // <f:if condition="{occurrenceIterator.isFirst}"> around either
        // rowspanned <td> in Index.html makes the exact-count assertion
        // fail (3 becomes 4, one per <tr> plus the duplicate).
        self::assertSame(
            2,
            substr_count(
                $titleRowHtml,
                'rowspan="3"',
            ),
            'The "title" row\'s 3 occurrences must produce exactly two rowspan="3" cells (attribute-name, '
            . 'example-value), not one per <tr>.',
        );

        // Pins the "attribute-overview-table_continued-row" class the
        // Module.css border/padding fixes key off (see that file's own
        // comments): it must land on exactly the 2 continuation <tr>s
        // (tt_content, tx_news_domain_model_news), never on the group's
        // first row. Revert-confirms-red verified: inverting the
        // "!{occurrenceIterator.isFirst}" condition in Index.html - so it
        // applies to the FIRST row instead - makes this exact-count
        // assertion fail (the count stays 2, but on the wrong rows; an
        // f:if dropped entirely would make it fail 0 vs 2).
        self::assertSame(
            2,
            substr_count(
                $titleRowHtml,
                'attribute-overview-table_continued-row',
            ),
            'The "title" row\'s 3 occurrences must mark exactly the 2 continuation rows with the '
            . 'continued-row class, never the group\'s first row.',
        );

        // The presence-only assertions above (badge/table/path checks)
        // don't pin which column each cell actually renders into - e.g. a
        // swapped Origin/Occurs-on <td> pair in Index.html (badge and
        // table name in the wrong columns) would still pass all of them.
        // Pin the group's first <tr>'s actual cell sequence: attribute
        // name, origin badge, table, path, example value, matching the
        // header order pinned above. Revert-confirms-red verified:
        // swapping the origin-badge and table-name <td> elements in
        // Index.html makes this fail.
        self::assertMatchesRegularExpression(
            '#<td rowspan="3"><code>title</code></td>\s*<td><span class="badge">typoscript</span></td>\s*'
            . '<td><code>pages</code></td>\s*<td>\s*'
            . '<code>module\.tx_typo3searchalgolia\.indexer\.pages\.fields\.title</code>\s*</td>\s*'
            . '<td rowspan="3">#s',
            $titleRowHtml,
            'The "title" row\'s first <tr> must render its cells in Attribute, Origin, Occurs on '
            . '(table), Path, Example value order.',
        );
    }

    /**
     * Verifies mergeTableAttributes()'s occurrence order within one
     * attribute's row group follows getRecordTypes()'s registration order
     * (pages, tt_content, sys_file_metadata, tx_news_domain_model_news),
     * never alphabetical order. Every other multi-occurrence test in this
     * class only ever combines pages/tt_content/tx_news_domain_model_news,
     * whose alphabetical and registration order happen to coincide -
     * sys_file_metadata is the one registered table whose alphabetical
     * position ('s', 2nd) diverges from its registration position (3rd,
     * after tt_content), so only a row containing BOTH tt_content and
     * sys_file_metadata can actually distinguish the two orderings.
     *
     * sys_file_metadata's row only ever comes from a real, auto-indexed
     * file (see attributeOverviewSuppressesTheStatusSectionWhenEveryRecordTypeHasPreviewData()'s
     * docblock), and the fixture PDF carries no embedded /Title metadata,
     * so its 'title' field is empty and skipped by
     * DocumentBuilder::addConfiguredFieldsToDocument() ("Skip empty
     * strings.") on a first pass. Calling indexAction() once first drives
     * the real filesystem scan that auto-creates the sys_file_metadata row
     * (a documented, real side effect of FileIndexer's scope query, not a
     * test artifact), then this test overwrites that row's title directly
     * so a second indexAction() call has a genuinely non-empty value to
     * classify as a TypoScript-origin 'title' occurrence.
     *
     * Revert-confirms-red verified: replacing mergeTableAttributes()'s
     * insertion-order occurrences array with one built via ksort() (a
     * plausible "sort for display consistency" mutation) leaves every
     * other test in this class green, but makes this test's order
     * assertion fail (sys_file_metadata would render before tt_content).
     */
    #[Test]
    public function attributeOverviewOrdersOccurrencesWithinARowByRecordTypeRegistrationOrderNotAlphabetically(): void
    {
        // attribute_overview_pages.csv is imported not because pages' own
        // 'title' occurrence matters to this test (the assertion below only
        // compares tt_content against sys_file_metadata), but because
        // tt_content's own scope query resolves recursively from the page
        // tree - without a real page 1 to recurse from, tt_content's fixture
        // row (pid=2) never enters scope at all, and the row this test
        // extracts wouldn't contain a tt_content occurrence to compare.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services_all_tables_ok.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        // First pass: no assertions, this only drives the real filesystem
        // scan that auto-creates the sys_file_metadata row for
        // fileadmin/test.pdf.
        $this->callIndexActionAndAssertOk($subject);

        $connectionPool = $this->getConnectionPool();

        $fileUid = $connectionPool
            ->getConnectionForTable('sys_file')
            ->select(
                ['uid'],
                'sys_file',
                ['name' => 'test.pdf'],
            )
            ->fetchOne();

        self::assertIsInt(
            $fileUid,
            'The fixture file must have been auto-indexed into sys_file by the first indexAction() call above.',
        );

        $connectionPool
            ->getConnectionForTable('sys_file_metadata')
            ->update(
                'sys_file_metadata',
                ['title' => 'File title'],
                ['file' => $fileUid],
            );

        $body = $this->callIndexActionAndAssertOk($subject);

        $titleRowHtml = $this->extractAttributeRowHtml(
            $body,
            'title',
        );

        $ttContentPosition       = strpos($titleRowHtml, '<code>tt_content</code>');
        $sysFileMetadataPosition = strpos($titleRowHtml, '<code>sys_file_metadata</code>');

        self::assertNotFalse($ttContentPosition);
        self::assertNotFalse($sysFileMetadataPosition);
        self::assertLessThan(
            $sysFileMetadataPosition,
            $ttContentPosition,
            "The 'title' row's tt_content occurrence must be rendered before its sys_file_metadata "
            . 'occurrence (registration order: tt_content is registered before sys_file_metadata), not '
            . 'alphabetically ("sys_file_metadata" < "tt_content").',
        );
    }

    /**
     * Verifies the positive "occurs on" framing that replaces the dropped
     * SchemaGapDetector's negatively-framed "missing on" list: 'categories'
     * is set only by UpdateAssembledPageDocumentEventListener (grepped,
     * no other listener touches it), so its row must carry exactly one
     * occurrence entry (pages), the absence of tt_content/News/
     * sys_file_metadata among its occurrences already communicates "not
     * carried by these tables" without a separate gap list.
     */
    #[Test]
    public function attributeOverviewShowsExactlyOneOccurrenceForAnAttributePresentOnOnlyOneTable(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_news_domain_model_news.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $categoriesRowHtml = $this->extractAttributeRowHtml(
            $body,
            'categories',
        );

        self::assertStringContainsString(
            '<code>pages</code>',
            $categoriesRowHtml,
        );
        self::assertStringNotContainsString(
            '<code>tt_content</code>',
            $categoriesRowHtml,
        );
        self::assertStringNotContainsString(
            '<code>tx_news_domain_model_news</code>',
            $categoriesRowHtml,
        );

        self::assertSame(
            1,
            substr_count(
                $categoriesRowHtml,
                '<span class="badge">',
            ),
            'The "categories" row must carry exactly one occurrence entry.',
        );
    }

    /**
     * Verifies each occurrence carries its own origin detail, not a single
     * shared one: 'title' is TypoScript-mapped on both pages (from the
     * source field 'title') and tt_content (from the source field
     * 'header'), so the two occurrences of the same attribute NAME must
     * show two DIFFERENT TypoScript paths.
     *
     * A same-name attribute classified TypoScript on one table and Listener
     * on another is not constructible from this extension's real
     * fixtures/configuration: the TypoScript-mapped target names (title,
     * subTitle, navTitle, description, teaser, author, keywords,
     * authorEmail, alternative) and the listener-set field names (site,
     * url, categories, content) are two disjoint sets in
     * Configuration/TypoScript/setup.typoscript and Classes/EventListener/
     * respectively, verified by reading both.
     */
    #[Test]
    public function attributeOverviewShowsADifferentTypoScriptDetailPerTableForTheSameAttributeName(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $titleRowHtml = $this->extractAttributeRowHtml(
            $body,
            'title',
        );

        self::assertStringContainsString(
            '<code>module.tx_typo3searchalgolia.indexer.pages.fields.title</code>',
            $titleRowHtml,
        );
        self::assertStringContainsString(
            '<code>module.tx_typo3searchalgolia.indexer.tt_content.fields.header</code>',
            $titleRowHtml,
        );
    }

    /**
     * Verifies the exampleValue rule: it must come from the FIRST table in
     * registration order (pages, tt_content, sys_file_metadata, News, see
     * getRecordTypes()) that carries the attribute, not from a later one.
     * 'title' is present on both pages (auto-picked record: "Second Page",
     * the highest-tstamp page) and tt_content (header "Content On Page",
     * TypoScript-mapped to the same 'title' target) - the exampleValue must
     * be pages' own value.
     */
    #[Test]
    public function attributeOverviewTakesTheExampleValueFromTheFirstRegisteredTableWherePresent(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $titleRowHtml = $this->extractAttributeRowHtml(
            $body,
            'title',
        );

        $this->assertExampleValueCellContains(
            'Second Page',
            $titleRowHtml,
            "The example value must be pages' own title (the auto-picked, highest-tstamp page), "
            . "not tt_content's header, even though both map to the 'title' attribute.",
        );
        self::assertStringNotContainsString(
            'Content On Page',
            $titleRowHtml,
        );
    }

    /**
     * Verifies mergeTableAttributes()'s "first NON-EMPTY value wins" rule:
     * a table contributing an empty exampleValue for a shared attribute
     * name must not permanently suppress a later table's genuinely
     * non-empty value for the same name. Chains two
     * StringFieldInjectingDocumentBuilder instances (pages first with an
     * empty value, tt_content second with a real one) onto the same
     * synthetic attribute name, mirroring this extension's own
     * Configuration/TypoScript/setup.typoscript, where both pages.subtitle
     * and tt_content.subheader map to the shared 'subTitle' target
     * attribute - so an auto-picked page with a blank subtitle would
     * otherwise permanently blank out a would-be-useful tt_content example.
     *
     * Revert-confirms-red verified: reverting mergeTableAttributes()'s
     * "===  ''" guard back to the plain "??=" it replaced makes this
     * assertion fail (the row renders with an empty exampleValue instead
     * of tt_content's real one).
     */
    #[Test]
    public function attributeOverviewTakesTheExampleValueFromALaterTableWhenTheFirstContributesAnEmptyOne(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $ttContentInjectingDocumentBuilder = new StringFieldInjectingDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $this->get(DocumentBuilder::class),
            'tt_content',
            'sharedExampleValueField',
            'Real Value From tt_content',
        );

        $pagesInjectingDocumentBuilder = new StringFieldInjectingDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $ttContentInjectingDocumentBuilder,
            'pages',
            'sharedExampleValueField',
            '',
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            $pagesInjectingDocumentBuilder,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $rowHtml = $this->extractAttributeRowHtml(
            $body,
            'sharedExampleValueField',
        );

        $this->assertExampleValueCellContains(
            'Real Value From tt_content',
            $rowHtml,
            "pages' own empty contribution must not permanently suppress tt_content's real value "
            . 'for the same attribute name.',
        );
    }

    /**
     * Verifies mergeTableAttributes()'s guard is a strict "!== ''" check,
     * not a loose/truthy one: the string "0" is falsy in PHP but is a
     * genuinely populated example value that a later table's contribution
     * must not overwrite. A loose check (e.g. "$existingExampleValue ?
     * $existingExampleValue : ...") would wrongly treat "0" the same as an
     * empty string, silently losing the first table's real value. This is
     * a reachable production scenario, not a contrived one: pid = 0 for
     * every top-level record, and formatExampleValue()'s is_scalar branch
     * casts it to the string "0".
     *
     * Revert-confirms-red verified: loosening mergeTableAttributes()'s
     * "$existingExampleValue !== ''" guard to a truthy check
     * ("$existingExampleValue") makes this assertion fail (the row renders
     * tt_content's value instead of pages' "0").
     */
    #[Test]
    public function attributeOverviewKeepsAFalsyButNonEmptyExampleValueFromAnEarlierTable(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $ttContentInjectingDocumentBuilder = new StringFieldInjectingDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $this->get(DocumentBuilder::class),
            'tt_content',
            'sharedExampleValueField',
            'Real Value From tt_content',
        );

        $pagesInjectingDocumentBuilder = new StringFieldInjectingDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $ttContentInjectingDocumentBuilder,
            'pages',
            'sharedExampleValueField',
            '0',
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            $pagesInjectingDocumentBuilder,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $rowHtml = $this->extractAttributeRowHtml(
            $body,
            'sharedExampleValueField',
        );

        $this->assertExampleValueCellContains(
            '0',
            $rowHtml,
            "pages' own falsy-but-non-empty '0' contribution must not be treated as unpopulated and "
            . "overwritten by tt_content's later value for the same attribute name.",
        );
    }

    /**
     * Verifies formatExampleValue()'s own claim that Fluid's default
     * auto-escaping already handles HTML-safety on output, since that
     * method deliberately does not pre-escape (see its own docblock).
     * Injects an example value containing HTML-significant characters (a
     * page title an editor legitimately entered, or a third-party
     * AfterDocumentAssembledEvent listener setting one, see the module's
     * own docblock on arbitrary listener-set values) and asserts the
     * rendered <td> carries the HTML-entity-encoded form, not raw markup -
     * guarding against a future template change (e.g. wrapping
     * {row.exampleValue} in <f:format.raw> "for convenience") silently
     * reintroducing a stored-XSS-style regression that every other test in
     * this file, using only plain alphanumeric example values, would stay
     * green against.
     *
     * Revert-confirms-red verified: wrapping {row.exampleValue} in
     * <f:format.raw> in Index.html makes this assertion fail.
     */
    #[Test]
    public function attributeOverviewEscapesHtmlSignificantCharactersInTheExampleValue(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $injectingDocumentBuilder = new StringFieldInjectingDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $this->get(DocumentBuilder::class),
            'pages',
            'htmlSignificantField',
            '<script>alert(1)</script> & "quoted"',
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            $injectingDocumentBuilder,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $rowHtml = $this->extractAttributeRowHtml(
            $body,
            'htmlSignificantField',
        );

        $this->assertExampleValueCellContains(
            '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quoted&quot;',
            $rowHtml,
            "The example value must be HTML-entity-encoded by Fluid's default auto-escaping, "
            . 'not rendered as raw markup.',
        );
        self::assertStringNotContainsString(
            '<script>alert(1)</script>',
            $rowHtml,
        );
    }

    /**
     * Verifies array-valued fields (e.g. 'categories', a string[]) are
     * comma-joined for display, using a dedicated fixture whose
     * auto-picked page carries two real, assigned categories.
     */
    #[Test]
    public function attributeOverviewFormatsAnArrayValuedFieldAsACommaJoinedExampleValue(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $categoriesRowHtml = $this->extractAttributeRowHtml(
            $body,
            'categories',
        );

        $this->assertExampleValueCellContains(
            'Category A, Category B',
            $categoriesRowHtml,
        );
    }

    /**
     * Verifies formatExampleValue()'s non-scalar array-item fallback
     * ("is_scalar($item) ? (string) $item : ''"): a document field
     * containing an array with a mix of scalar and non-scalar items (a
     * nested array, simulating a third-party AfterDocumentAssembledEvent
     * listener setting such a value, see the module's own docblock on
     * arbitrary listener-set values) must render with the non-scalar item
     * shown as an empty string in its own position, not throw, not be
     * silently dropped, and not corrupt the other, scalar items around it.
     *
     * Uses ArrayFieldInjectingDocumentBuilder to inject exactly this shaped
     * field onto the real, fully assembled Document for the 'pages' table,
     * through the real public flow (indexAction() -> buildTableAttributes()
     * -> formatExampleValue()), rather than calling the private method
     * directly.
     *
     * Revert-confirms-red verified: temporarily changing the fallback from
     * "''" to "'BROKEN'" makes this assertion fail.
     */
    #[Test]
    public function attributeOverviewFormatsANonScalarArrayItemAsAnEmptyStringInItsPosition(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $injectingDocumentBuilder = new ArrayFieldInjectingDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $this->get(DocumentBuilder::class),
            'pages',
            'nonScalarArrayField',
            ['a', ['nested' => 'array'], 'b'],
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            $injectingDocumentBuilder,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $rowHtml = $this->extractAttributeRowHtml(
            $body,
            'nonScalarArrayField',
        );

        $this->assertExampleValueCellContains(
            'a, , b',
            $rowHtml,
            'The non-scalar (nested array) item must be rendered as an empty string in its own position, '
            . 'surrounded by the correctly-rendered scalar items.',
        );
    }

    /**
     * Verifies formatExampleValue()'s outer "neither array nor scalar"
     * fallback (the final "else" branch, distinct from the array-item
     * fallback attributeOverviewFormatsANonScalarArrayItemAsAnEmptyStringInItsPosition()
     * above covers): a document field holding an object directly, not
     * wrapped in an array, simulating a third-party
     * AfterDocumentAssembledEvent listener setting such a value (see the
     * module's own docblock on arbitrary listener-set values), must render
     * as an empty string, not throw, and not corrupt the row around it.
     *
     * Uses ObjectFieldInjectingDocumentBuilder to inject exactly this
     * shaped field onto the real, fully assembled Document for the 'pages'
     * table, through the real public flow (indexAction() ->
     * buildTableAttributes() -> formatExampleValue()), rather than calling
     * the private method directly.
     *
     * Revert-confirms-red verified: temporarily changing the fallback from
     * "''" to "'BROKEN'" makes this assertion fail.
     */
    #[Test]
    public function attributeOverviewFormatsANonArrayNonScalarFieldValueAsAnEmptyString(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $injectingDocumentBuilder = new ObjectFieldInjectingDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $this->get(DocumentBuilder::class),
            'pages',
            'objectField',
            new DateTimeImmutable(),
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            $injectingDocumentBuilder,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $rowHtml = $this->extractAttributeRowHtml(
            $body,
            'objectField',
        );

        $this->assertExampleValueCellContains(
            '',
            $rowHtml,
            'A non-array, non-scalar field value must be rendered as an empty string.',
        );
    }

    /**
     * Verifies formatExampleValue()'s
     * "!array_key_exists($attributeName, $fields)" guard: an
     * AttributeOriginResolverInterface implementation is a documented
     * public-API extension point, so nothing in its contract guarantees a
     * returned AttributeOriginMap's attribute names are a subset of the
     * assembled Document's own field names, that invariant is only upheld
     * by the one shipped resolver, not by the interface itself. A
     * third-party implementation classifying an attribute name absent from
     * the document's fields must render as an empty string, not throw.
     *
     * Uses PhantomAttributeInjectingOriginResolver to inject exactly such a
     * phantom attribute name for the 'pages' table, through the real public
     * flow (indexAction() -> buildTableAttributes() -> mergeTableAttributes()
     * -> formatExampleValue()), rather than calling the private method
     * directly.
     *
     * Revert-confirms-red verified: temporarily removing the
     * "!array_key_exists(...)" guard leaves this test's own assertions
     * passing (accessing the missing key yields NULL, which is neither an
     * array nor scalar, so the code still falls through to the same
     * empty-string result), but PHPUnit itself reports the run as failing
     * (exit code 1, "1 test triggered 1 PHP warning: Undefined array key
     * \"phantomAttribute\""), so the guard is not merely cosmetic even
     * though this particular assertion alone can't discriminate it.
     */
    #[Test]
    public function attributeOverviewFormatsAPhantomAttributeNameAsAnEmptyStringWithoutThrowing(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $injectingOriginResolver = new PhantomAttributeInjectingOriginResolver(
            $this->get(AttributeOriginResolverInterface::class),
            'pages',
            'phantomAttribute',
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            null,
            null,
            $injectingOriginResolver,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $rowHtml = $this->extractAttributeRowHtml(
            $body,
            'phantomAttribute',
        );

        $this->assertExampleValueCellContains(
            '',
            $rowHtml,
            "An attribute name absent from the document's own fields must render as an empty string.",
        );
    }

    /**
     * Boundary case: a string exactly EXAMPLE_VALUE_MAX_LENGTH (150)
     * characters long must NOT be truncated. Paired with
     * attributeOverviewTruncatesAnExampleValueLongerThanTheLengthLimit()
     * below (151 characters), which must be. Revert-confirms-red verified:
     * temporarily lowering the boundary (>=  instead of >) makes this
     * assertion fail.
     */
    #[Test]
    public function attributeOverviewDoesNotTruncateAnExampleValueAtExactlyTheLengthLimit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_description_at_boundary.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $descriptionRowHtml = $this->extractAttributeRowHtml(
            $body,
            'description',
        );

        $this->assertExampleValueCellContains(
            str_repeat(
                'A',
                150,
            ),
            $descriptionRowHtml,
            'An exactly-150-character value must be shown in full, not truncated.',
        );
        self::assertStringNotContainsString(
            '…',
            $descriptionRowHtml,
        );
    }

    /**
     * Boundary case: a string one character over EXAMPLE_VALUE_MAX_LENGTH
     * (151 characters) must be truncated to the first 150 characters plus a
     * trailing ellipsis marker. Revert-confirms-red verified: temporarily
     * removing the truncation branch makes this assertion fail.
     */
    #[Test]
    public function attributeOverviewTruncatesAnExampleValueLongerThanTheLengthLimit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_description_over_boundary.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $descriptionRowHtml = $this->extractAttributeRowHtml(
            $body,
            'description',
        );

        $this->assertExampleValueCellContains(
            str_repeat(
                'A',
                150,
            ) . '…',
            $descriptionRowHtml,
            'A 151-character value must be truncated to exactly 150 characters plus an ellipsis marker.',
        );
        self::assertStringNotContainsString(
            str_repeat(
                'A',
                151,
            ),
            $descriptionRowHtml,
        );
    }

    /**
     * Boundary case, multi-byte variant of
     * attributeOverviewDoesNotTruncateAnExampleValueAtExactlyTheLengthLimit():
     * formatExampleValue()'s own docblock states the EXAMPLE_VALUE_MAX_LENGTH
     * contract counts CHARACTERS, which is exactly why it uses mb_strlen()/
     * mb_substr() rather than strlen()/substr(). A value made of 149 ASCII
     * characters plus one accented character ('é', 2 bytes in UTF-8) is
     * exactly 150 real characters but 151 bytes, so it only discriminates
     * correctly under mb_strlen() - the sibling ASCII-only boundary test
     * above cannot tell mb_strlen() apart from strlen() (a single-byte
     * string has an identical byte and character count). Revert-confirms-
     * red verified: temporarily replacing mb_strlen()/mb_substr() with
     * strlen()/substr() makes this assertion fail (the value is wrongly
     * judged 151 bytes long and truncated, additionally slicing 'é'
     * mid-byte into invalid UTF-8).
     */
    #[Test]
    public function attributeOverviewDoesNotTruncateAMultibyteExampleValueAtExactlyTheCharacterLengthLimit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_description_at_boundary_multibyte.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $descriptionRowHtml = $this->extractAttributeRowHtml(
            $body,
            'description',
        );

        $this->assertExampleValueCellContains(
            str_repeat(
                'A',
                149,
            ) . 'é',
            $descriptionRowHtml,
            'A value of exactly 150 real characters (149 ASCII + 1 multi-byte) must be shown in full, not truncated.',
        );
        self::assertStringNotContainsString(
            '…',
            $descriptionRowHtml,
        );
    }

    /**
     * Boundary case, multi-byte variant of
     * attributeOverviewTruncatesAnExampleValueLongerThanTheLengthLimit():
     * a value of one accented character ('é') followed by 150 ASCII
     * characters is 151 real characters, one over the limit, and must be
     * truncated to the first 150 real characters (not bytes) plus a
     * trailing ellipsis marker, i.e. 'é' followed by 149 'A's, dropping
     * only the LAST 'A'.
     *
     * The 'é' is placed at the START of the value, not the end, so a
     * byte-based cut genuinely diverges from a character-based one: 'é'
     * followed by 150 'A's is 152 bytes, and a byte offset of 150 lands
     * inside the ASCII run (2 bytes for 'é' + 148 bytes of 'A's), one
     * character short of the correct, character-counted cutoff. An
     * earlier version of this test placed 'é' at the END of the value
     * instead, where the byte and character cutoffs happen to coincide
     * (the ASCII prefix is itself exactly 150 bytes long), so it silently
     * did not discriminate mb_strlen()/mb_substr() from strlen()/substr()
     * despite claiming to - caught by test-quality-reviewer, php-typo3-
     * reviewer, and evidence-reviewer independently in the same audit
     * round, the latter by actually reverting and rerunning the test.
     *
     * Revert-confirms-red verified: temporarily replacing
     * mb_strlen()/mb_substr() with strlen()/substr() makes this assertion
     * fail (the byte-based cut yields 'é' plus 148 'A's instead of the
     * correct 149).
     */
    #[Test]
    public function attributeOverviewTruncatesAMultibyteExampleValueLongerThanTheCharacterLengthLimit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_description_over_boundary_multibyte.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $descriptionRowHtml = $this->extractAttributeRowHtml(
            $body,
            'description',
        );

        $this->assertExampleValueCellContains(
            'é' . str_repeat(
                'A',
                149,
            ) . '…',
            $descriptionRowHtml,
            "A 151-real-character value ('é' + 150 ASCII) must be truncated to exactly the first 150 real "
            . "characters ('é' + 149 'A's, dropping only the last 'A') plus an ellipsis marker.",
        );
        self::assertStringNotContainsString(
            'é' . str_repeat(
                'A',
                150,
            ),
            $descriptionRowHtml,
        );
    }

    /**
     * Verifies the "no indexing service configured at all" table-status
     * outcome: sys_file_metadata has no indexing service row in this
     * fixture set, so it must appear in the status list with the right
     * message and must contribute nothing to the attribute table (checked
     * by scoping the "must not appear" assertion to the attribute table's
     * own markup, not the whole body, since the table name legitimately
     * appears once in its own status line).
     */
    #[Test]
    public function attributeOverviewListsATableWithNoIndexingServiceInTheStatusListAndContributesNoAttributes(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $statusLineHtml = $this->extractStatusLineHtml(
            $body,
            'sys_file_metadata',
        );

        self::assertStringContainsString(
            'No indexing service configured for this record type.',
            $statusLineHtml,
        );

        $attributeTableHtml = $this->extractAttributeTableHtml($body);

        self::assertStringNotContainsString(
            '<code>sys_file_metadata</code>',
            $attributeTableHtml,
        );
    }

    /**
     * Verifies buildTableAttributes()'s OTHER route to
     * STATUS_NO_INDEXING_SERVICE: an indexing service IS configured for the
     * table (unlike
     * attributeOverviewListsATableWithNoIndexingServiceInTheStatusListAndContributesNoAttributes()
     * above, where none is), but IndexerFactory::makeInstanceByType()
     * itself returns NULL for it. This branch is unreachable through real
     * production wiring (getRecordTypes() only ever iterates
     * IndexerRegistry::getRegisteredIndexers(), which makeInstanceByType()
     * is always able to resolve), but it is still real defensive code
     * guarding a documented public-API ?IndexerInterface return type, not
     * dead code, so it needs a dedicated test double forcing that outcome
     * (NullReturningIndexerFactory) rather than being left unverified.
     *
     * Revert-confirms-red verified: temporarily removing the
     * "!($indexer instanceof IndexerInterface)" guard makes this assertion
     * fail with an Error ("Call to a member function
     * withIndexingService() on null") instead of the expected status line.
     */
    #[Test]
    public function attributeOverviewListsATableAsNoIndexingServiceWhenTheFactoryReturnsNull(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $nullReturningIndexerFactory = new NullReturningIndexerFactory(
            $this->get(IndexerFactory::class),
            'pages',
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            null,
            $nullReturningIndexerFactory,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $statusLineHtml = $this->extractStatusLineHtml(
            $body,
            'pages',
        );

        self::assertStringContainsString(
            'No indexing service configured for this record type.',
            $statusLineHtml,
        );

        $attributeTableHtml = $this->extractAttributeTableHtml($body);

        self::assertStringNotContainsString(
            '<code>pages</code>',
            $attributeTableHtml,
        );
    }

    /**
     * Verifies the "indexing service configured but zero in-scope records"
     * table-status outcome, distinct from "no indexing service at all": the
     * pages indexing service fixture here filters on pages_doktype=99,
     * which none of the imported pages match.
     */
    #[Test]
    public function attributeOverviewListsATableWithNoInScopeRecordsInTheStatusListAndContributesNoAttributes(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_service_no_scope_match.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $statusLineHtml = $this->extractStatusLineHtml(
            $body,
            'pages',
        );

        self::assertStringContainsString(
            'No record of this type is currently in scope.',
            $statusLineHtml,
        );
        self::assertStringNotContainsString(
            'No indexing service configured for this record type.',
            $statusLineHtml,
        );

        $attributeTableHtml = $this->extractAttributeTableHtml($body);

        self::assertStringNotContainsString(
            '<code>pages</code>',
            $attributeTableHtml,
        );
    }

    /**
     * Verifies the per-table try/catch in indexAction() (catching
     * Throwable, not just Exception, see ThrowingForTableDocumentBuilder's
     * docblock for why that distinction matters) genuinely isolates a
     * single table's failed preview: forces buildTableAttributes() to throw
     * a plain \Error for tt_content only, and asserts (a) tt_content's
     * error is shown in the status list, and (b) pages' attributes, in the
     * exact same response, still appear in the attribute table, proving the
     * failure did not abort the whole module.
     */
    #[Test]
    public function attributeOverviewIsolatesAPerTableFailureAndStillAggregatesOtherTables(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $throwingDocumentBuilder = new ThrowingForTableDocumentBuilder(
            $this->get(EventDispatcherInterface::class),
            $this->get(TypoScriptService::class),
            $this->get(DocumentBuilder::class),
            'tt_content',
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            $throwingDocumentBuilder,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        // Fluid HTML-encodes {status.message} by default, so the double
        // quotes around the table name in the message come back as &quot;.
        $statusLineHtml = $this->extractStatusLineHtml(
            $body,
            'tt_content',
        );

        self::assertStringContainsString(
            'Failed to build the preview for this record type: Simulated document assembly failure for table &quot;tt_content&quot;.',
            $statusLineHtml,
        );

        $uidRowHtml = $this->extractAttributeRowHtml(
            $body,
            'uid',
        );

        self::assertStringContainsString(
            '<code>pages</code>',
            $uidRowHtml,
            "A different, unaffected table's contribution must still appear in the attribute table "
            . 'in the same response as a table whose preview build threw.',
        );
        self::assertStringNotContainsString(
            '<code>tt_content</code>',
            $uidRowHtml,
            'tt_content must not contribute to any attribute row since its preview build threw.',
        );
    }

    /**
     * Guards against a regression of the bug fixed in f13dc65: buildTableAttributes()
     * unions findRecordUidsInScope() across every indexing service
     * configured for a table, but must assemble the auto-picked record under
     * the indexing service it was ACTUALLY found under, not always the
     * first configured one.
     *
     * The pages fixtures here configure TWO indexing services with disjoint
     * scopes: "Page Indexer A" (uid 1, pages_doktype=1, no content elements)
     * only ever finds page uid 20 (tstamp 1000), "Page Indexer B" (uid 2,
     * pages_doktype=4, WITH content elements) only ever finds page uid 21
     * (tstamp 2000, the higher one). Page 21 is therefore both the
     * auto-picked record (mostRecentlyChanged()) AND reachable exclusively
     * through service B, so its assembled document only carries a 'content'
     * attribute if it is genuinely assembled under service B's
     * isIncludeContentElements()=true configuration. If the f13dc65 bug
     * were reintroduced, buildTableAttributes() would instead assemble page
     * 21 under indexingServices[0] (service A, includeContentElements
     * false), and the 'content' attribute would never appear.
     *
     * Narrower than the pre-redesign version of this test, which could also
     * select page 20 (service A's own record) via the since-removed manual
     * override and assert the ABSENCE of 'content' there too; with no
     * override, only the auto-picked record (page 21) can be observed here.
     */
    #[Test]
    public function attributeOverviewAssemblesTheAutoPickedRecordUnderTheIndexingServiceItWasActuallyFoundUnder(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_multi_service.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_multi_service.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services_multi_service.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $contentRowHtml = $this->extractAttributeRowHtml(
            $body,
            'content',
        );

        self::assertStringContainsString(
            '<code>pages</code>',
            $contentRowHtml,
            'Page 21 (auto-picked: highest tstamp) is only in scope under "Page Indexer B" '
            . '(includeContentElements=true); a "content" attribute must appear for pages, proving '
            . 'assembly happened under that service, not indexingServices[0].',
        );
    }

    /**
     * Proves the "keep the first configured indexing service" tie-break in
     * buildTableAttributes() ($indexingServiceByRecordUid[$recordUid] ??=
     * $indexingService;) is genuinely load-bearing when two indexing
     * services for the SAME table have OVERLAPPING scopes and both match
     * the SAME record.
     *
     * The fixtures here configure both "Overlap Indexer A" (uid 1,
     * includeContentElements=false) and "Overlap Indexer B" (uid 2,
     * includeContentElements=true) with the IDENTICAL scope, so page uid 40
     * (the only real content page, and therefore also the auto-pick) is
     * genuinely in scope under BOTH. Since findAllByTableName() returns the
     * two services uid-ascending and buildTableAttributes() processes them
     * in that order, page 40 is recorded under Service A on the first pass
     * and Service B's later, redundant match for the same UID is discarded
     * by ??=. If the operator were a plain = (last-service-wins) instead,
     * page 40 would be assembled under Service B (includeContentElements=
     * true) and would carry a 'content' attribute, flipping the outcome
     * asserted below - spot-checked by temporarily reverting ??= to =
     * locally, which turns this assertion red.
     */
    #[Test]
    public function attributeOverviewKeepsTheFirstConfiguredIndexingServiceWhenScopesOverlap(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_overlap.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_overlap.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services_overlap.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $attributeTableHtml = $this->extractAttributeTableHtml($body);

        self::assertStringNotContainsString(
            '<code>content</code>',
            $attributeTableHtml,
            'Page 40 is in scope under BOTH overlapping indexing services; the FIRST configured one '
            . '("Overlap Indexer A", includeContentElements=false) must win, so no "content" attribute may '
            . 'appear at all. A reintroduced last-match-wins behaviour would instead assemble page 40 under '
            . '"Overlap Indexer B" (includeContentElements=true) and this assertion would fail.',
        );
    }

    /**
     * Proves mostRecentlyChanged()'s documented uid DESC tie-break is
     * actually load-bearing: this dedicated fixture
     * (attribute_overview_pages_tstamp_tie.csv) gives pages uid=2 and uid=3
     * the exact same tstamp (3000), both higher than the root page's. The
     * exampleValue for 'title' must reflect uid=3 ("Second Page"), the
     * higher-uid tie-winner, not uid=2.
     */
    #[Test]
    public function attributeOverviewPicksTheHigherUidOnATstampTie(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_tstamp_tie.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $titleRowHtml = $this->extractAttributeRowHtml(
            $body,
            'title',
        );

        $this->assertExampleValueCellContains(
            'Second Page',
            $titleRowHtml,
        );
        self::assertStringNotContainsString(
            'First Page',
            $titleRowHtml,
        );
    }

    /**
     * Proves AttributeOverviewModuleController::mostRecentlyChanged()'s
     * "$GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? 'uid'" fallback is
     * genuinely reachable and correctly wired as the ORDER BY column.
     *
     * Piggybacks on the synthetic tx_news_domain_model_news TCA setUp()
     * already registers, mutating it further, ONLY within this one test
     * method, to remove ctrl.tstamp entirely - this mutation does not leak
     * into any other test method, every test method runs its own setUp().
     *
     * Imports attribute_overview_pages.csv purely for its page tree (root
     * page uid=1, needed by NewsIndexer::findRecordUidsInScope()'s
     * pages_recursive resolution to find the News rows at all), but pairs
     * it with a dedicated indexing-services fixture that configures ONLY a
     * News indexing service, not a Page one: 'title' is TypoScript-mapped
     * on every table, so if pages had its OWN indexing service too, its
     * value (first in registration order) would be shown instead of News',
     * making the fallback invisible to this assertion. Without a Page
     * indexing service, pages contributes nothing and News becomes the
     * only (and therefore first) table carrying 'title', so its
     * exampleValue directly reflects which News record the fallback
     * picked.
     *
     * The dedicated attribute_overview_news_tstamp_fallback.csv fixture
     * gives uid=1 the highest tstamp (5000) and uid=3 the highest uid but
     * the LOWEST tstamp (1000) - the two orderings deliberately disagree.
     * With ctrl.tstamp intact, the pick would be uid=1. With it removed,
     * the fallback orders by 'uid' DESC instead, so the pick must be uid=3,
     * the opposite record.
     */
    #[Test]
    public function attributeOverviewAutoPicksByUidDescendingWhenTheTablesTcaHasNoTstampField(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_news_tstamp_fallback.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_service_news_only.csv');

        $GLOBALS['TCA']['tx_news_domain_model_news']['ctrl']['tstamp'] = null;
        unset($GLOBALS['TCA']['tx_news_domain_model_news']['ctrl']['enablecolumns']['starttime']);

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        $titleRowHtml = $this->extractAttributeRowHtml(
            $body,
            'title',
        );

        $this->assertExampleValueCellContains(
            'News with the highest uid, lowest tstamp',
            $titleRowHtml,
            'With ctrl.tstamp removed, the fallback field is uid, so the highest-uid record (3, the '
            . 'lowest-tstamp of the three) must be auto-picked, not uid=1 (the highest-tstamp record).',
        );
        self::assertStringNotContainsString(
            'News with the highest tstamp',
            $titleRowHtml,
        );
    }

    /**
     * Guards against SCOPE_RECORD_LIMIT silently getting dropped, zeroed, or
     * hardcoded to a wrong value in buildTableAttributes() - a real,
     * serious regression on a large table, since 0 means "unbounded" per
     * IndexerInterface::findRecordUidsInScope()'s own docblock, so a broken
     * cap would turn this admin-only diagnostic module into an uncached
     * full-table scan on every page load.
     *
     * Which record ultimately gets auto-picked cannot discriminate this:
     * AbstractIndexer::fetchRecords() already orders by tstamp DESC before
     * applying the SQL LIMIT, so the true most-recently-changed record
     * (attribute_overview_pages_scope_limit.csv's page uid=206, the highest
     * tstamp) is within the top-N-by-recency subset whether N is 200, 5, or
     * unbounded - the pre-redesign test's own dropdown-option-count
     * assertion mechanism no longer exists, and a naive replacement built
     * around the auto-picked record would pass identically whether the cap
     * is real or broken.
     *
     * Instead, LimitCapturingIndexerFactory substitutes a
     * LimitCapturingIndexer wrapping the real 'pages' indexer, which
     * records the exact $limit argument
     * IndexerInterface::findRecordUidsInScope() was actually called with.
     * This directly proves the constant is threaded through to the call
     * (the actual risk), reading SCOPE_RECORD_LIMIT via reflection rather
     * than hardcoding 200, mirroring the deleted test's own
     * anti-magic-number technique. The 206-in-scope-record fixture (206
     * beyond the 200 cap) additionally lets the capture prove the database
     * layer genuinely bounds the result to that limit, not just that the
     * right number was passed down.
     *
     * Revert-confirms-red verified: temporarily hardcoding
     * "findRecordUidsInScope(0)" in buildTableAttributes() makes the first
     * assertion fail (capturedLimit becomes 0, not 200); temporarily
     * hardcoding a wrong constant (e.g. 5) makes it fail differently
     * (capturedLimit becomes 5, still not equal to the real
     * SCOPE_RECORD_LIMIT); both are restored afterwards and the test passes
     * again.
     */
    #[Test]
    public function buildTableAttributesPassesTheRealScopeRecordLimitConstantToFindRecordUidsInScope(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_scope_limit.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_service_scope_limit.csv');

        $limitCapture                 = new LimitCapture();
        $limitCapturingIndexerFactory = new LimitCapturingIndexerFactory(
            $this->get(IndexerFactory::class),
            'pages',
            $limitCapture,
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            null,
            $limitCapturingIndexerFactory,
        );

        $this->callIndexActionAndAssertOk($subject);

        $scopeRecordLimit = (int) (new ReflectionClass(AttributeOverviewModuleController::class))
            ->getConstant('SCOPE_RECORD_LIMIT');

        self::assertSame(
            $scopeRecordLimit,
            $limitCapture->capturedLimit,
            'buildTableAttributes() must pass its own SCOPE_RECORD_LIMIT constant to '
            . 'findRecordUidsInScope(), not a dropped, zeroed, or hardcoded-wrong value.',
        );
        self::assertSame(
            $scopeRecordLimit,
            $limitCapture->capturedRecordUidCount,
            '206 in-scope records were made available, beyond the 200 cap, so the actually '
            . 'returned UID count must be capped at exactly SCOPE_RECORD_LIMIT, not left unbounded.',
        );
    }

    /**
     * Guards against DocumentBuilder::assemble() receiving an empty record
     * array. The record buildTableAttributes() picks via
     * findRecordUidsInScope() + mostRecentlyChanged() can still be gone by
     * the time the subsequent "SELECT * WHERE uid = $mostRecentRecordUid"
     * runs, either a genuine delete race, or (as reproduced here) a custom
     * IndexerInterface implementation - a documented public-API extension
     * point - returning a stale or otherwise non-existent record UID.
     *
     * PhantomRecordUidIndexerFactory substitutes a PhantomRecordUidIndexer
     * wrapping the real 'pages' indexer, whose findRecordUidsInScope()
     * always returns a single UID (999999) that matches no imported page.
     * Without the buildTableAttributes() guard, this would pass an empty
     * array into DocumentBuilder::assemble(), which reads $record['uid']
     * unconditionally - an "Undefined array key" PHP warning that fails
     * the build under this project's own failOnWarning PHPUnit
     * configuration, instead of the same "nothing to preview" outcome an
     * empty scope already produces.
     *
     * Revert-confirms-red verified: removing the "if ($record === false)"
     * guard in buildTableAttributes() makes this test fail with exactly
     * that PHPUnit warning-to-failure conversion; restoring the guard
     * makes it pass again.
     */
    #[Test]
    public function buildTableAttributesFallsBackToNoRecordInScopeWhenThePickedRecordVanishes(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $phantomRecordUidIndexerFactory = new PhantomRecordUidIndexerFactory(
            $this->get(IndexerFactory::class),
            'pages',
            999999,
        );

        $subject = $this->createDrivenSubject(
            ['id' => 0],
            null,
            $phantomRecordUidIndexerFactory,
        );

        $body = $this->callIndexActionAndAssertOk($subject);

        $statusLineHtml = $this->extractStatusLineHtml(
            $body,
            'pages',
        );

        self::assertStringContainsString(
            'No record of this type is currently in scope.',
            $statusLineHtml,
        );

        $attributeTableHtml = $this->extractAttributeTableHtml($body);

        self::assertStringNotContainsString(
            '<code>pages</code>',
            $attributeTableHtml,
        );
    }

    /**
     * Guards the opposite direction of the module's "table status" section:
     * <f:if condition="{tableStatuses}"> in Index.html must suppress the
     * "Record types without preview data" heading and list entirely once
     * every registered record type actually has preview data, not just
     * render it with an empty list. Every other test in this class
     * configures at least one table without an indexing service or without
     * an in-scope record specifically to exercise that section, so this
     * branch of the same condition has no coverage anywhere else.
     *
     * Configures all four record types the extension registers in this
     * test environment (pages, tt_content, sys_file_metadata,
     * tx_news_domain_model_news, see ext_localconf.php) with a working
     * indexing service and at least one matching in-scope record each.
     * sys_file_metadata's record comes from a real fixture file provided at
     * fileadmin/test.pdf (see $pathsToProvideInTestInstance above), not a
     * CSV row: the Local storage driver's file listing scans the real
     * filesystem, so FileIndexer's scope query only ever finds files that
     * physically exist there, TYPO3 auto-indexes it into a fresh sys_file /
     * sys_file_metadata row on first access.
     *
     * Revert-confirms-red verified: temporarily changing Index.html's
     * condition to unconditionally render the section (e.g.
     * "<f:if condition="1">") makes the "must not render" assertion below
     * fail; reverting restores the pass.
     */
    #[Test]
    public function attributeOverviewSuppressesTheStatusSectionWhenEveryRecordTypeHasPreviewData(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_news_domain_model_news.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services_all_tables_ok.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $body = $this->callIndexActionAndAssertOk($subject);

        self::assertStringNotContainsString(
            'Record types without preview data',
            $body,
            'The table-status section must not render at all once every '
            . 'registered record type has preview data.',
        );

        $attributeTableHtml = $this->extractAttributeTableHtml($body);

        foreach (['pages', 'tt_content', 'sys_file_metadata', 'tx_news_domain_model_news'] as $table) {
            self::assertStringContainsString(
                '<code>' . $table . '</code>',
                $attributeTableHtml,
                'Table "' . $table . '" was expected to contribute at least one attribute occurrence.',
            );
        }
    }
}
