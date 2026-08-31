<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Controller\AttributeOverviewModuleController;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginResolverInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\NewsIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SchemaGapDetector;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptServiceInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller\AttributeOverviewModuleControllerTestSubject;
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
use function substr_count;

/**
 * Functional tests for AttributeOverviewModuleController.
 *
 * As a functional test, this drives a real, DI-resolved controller against
 * real CSV fixtures loaded into a real (SQLite) test database, so the real
 * in-extension AfterDocumentAssembledEvent listeners
 * (UpdateAssembledPageDocumentEventListener,
 * UpdateAssembledContentElementDocumentEventListener) fire for real during
 * DocumentBuilder::assemble() - nothing about origin classification or gap
 * detection is mocked.
 *
 * Deviation from the task brief's fixture plan, evidenced below: the brief's
 * Step 1 asked for a tx_news_domain_model_news indexing service to be
 * deliberately OMITTED, reasoning that its absence would make it show up in
 * the runtime "missing on" gap list. Tracing the actual controller code
 * disproves that: AttributeOverviewModuleController::indexAction() only adds
 * a table to $originMaps when its section's originMap is non-null
 * ("if ($section['originMap'] !== null) { $originMaps[$table] = ...; }"),
 * and buildSection() returns a null originMap whenever a table has NO
 * indexing service row at all ('noIndexingService' => true). A table with no
 * indexing service is therefore excluded from SchemaGapDetector::diff()'s
 * $allTypes entirely - it can never appear as "missing on" for anything, it
 * is simply never compared. This is the module's intended error handling
 * for that case: a record type with no indexing service configured at all
 * has nothing to compare in the first place, so it is skipped in gap
 * detection rather than reported as "missing" every attribute.
 *
 * The design doc's own worked example for the runtime gap
 * ("site: present on pages, tt_content, sys_file_metadata - missing on
 * tx_news_domain_model_news (runtime)") in fact assumes the OPPOSITE: News
 * DOES have an indexing service (it is actively indexed) but its assembled
 * document is missing fields a per-table listener would otherwise add,
 * because - verified against Classes/EventListener/ - no
 * UpdateAssembledNewsDocumentEventListener exists at all, unlike pages and
 * tt_content which each have their own listener.
 *
 * This test therefore gives tx_news_domain_model_news a real indexing
 * service and real records (Fixtures/Database/tx_news_domain_model_news.csv,
 * reused from NewsIndexerTest's fixture set, plus the same
 * create_tx_news_domain_model_news.sql/TCA setup NewsIndexerTest uses, since
 * the news extension itself is not a hard dependency), so its document
 * assembly runs for real and genuinely lacks the 'site'/'categories'
 * attributes pages and/or tt_content carry - a real, reachable runtime gap,
 * rather than an unreachable one. sys_file_metadata is left without an
 * indexing service (as the brief intended), independently covering the
 * "No indexing service configured for this record type." assertion.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(AttributeOverviewModuleController::class)]
#[UsesClass(AttributeOverviewModuleControllerTestSubject::class)]
#[UsesClass(ThrowingForTableDocumentBuilder::class)]
final class AttributeOverviewModuleControllerTest extends AbstractFunctionalTestCase
{
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
        // registration ext_localconf.php performs, so tx_news_domain_model_news
        // is actually covered by the module under test, matching the "News
        // DOES have an indexing service" premise this test class relies on.
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
    private function createSubject(?DocumentBuilder $documentBuilder = null): AttributeOverviewModuleControllerTestSubject
    {
        return new AttributeOverviewModuleControllerTestSubject(
            $this->get(ModuleTemplateFactory::class),
            $this->get(IconFactory::class),
            $this->get(IndexingServiceRepository::class),
            $this->get(ConnectionPool::class),
            $this->get(IndexerFactory::class),
            $documentBuilder ?? $this->get(DocumentBuilder::class),
            $this->get(AttributeOriginResolverInterface::class),
            $this->get(SchemaGapDetector::class),
            $this->get(TypoScriptServiceInterface::class),
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
            ->withAttribute('route', $route)
            ->withAttribute('extbase', $extbaseRequestParameters)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($_SERVER))
            ->withQueryParams($queryParams);

        return new Request($serverRequest);
    }

    /**
     * Extracts a single table's own section markup from the rendered
     * module body, so an assertion can be scoped to that one table and not
     * accidentally match content belonging to a different table's section
     * further down the page. Bounded from the table's own <code>{table}</code>
     * heading up to (but not including) the next section's opening
     * "<div class=\"mt-4\">" or the closing "</form>", whichever comes first.
     */
    private function extractSectionHtml(string $body, string $table): string
    {
        $matched = preg_match(
            '#<code>' . preg_quote($table, '#') . '</code></h3>(.*?)(?:<div class="mt-4">|</form>)#s',
            $body,
            $matches,
        );

        self::assertSame(
            1,
            $matched,
            'Expected exactly one rendered section for table "' . $table . '".',
        );

        return $matches[1];
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function createDrivenSubject(
        array $queryParams,
        ?DocumentBuilder $documentBuilder = null,
    ): AttributeOverviewModuleControllerTestSubject {
        $request = $this->createModuleRequest($queryParams);

        $subject = $this->createSubject($documentBuilder);
        $subject->setRequestForTest($request);
        $subject->setModuleTemplateForTest(
            $this->get(ModuleTemplateFactory::class)->create($request),
        );

        return $subject;
    }

    /**
     * Verifies the rendered view: (1) shows an origin badge for at least
     * one Default-classified attribute of the pages record (proving the
     * resolver ran and its result reached the template, not just that the
     * module booted), and (2) lists tx_news_domain_model_news specifically
     * inside the runtime-gap section, not merely somewhere on the page
     * (that section only renders anything for a table that is genuinely
     * missing an attribute another type has). See the class docblock for
     * why the fixtures give News a real indexing service instead of
     * omitting one - the 'site'/'categories' gap is real: pages' and/or
     * tt_content's own AfterDocumentAssembledEvent listener adds those
     * fields, and no equivalent listener exists for News.
     */
    #[Test]
    public function indexActionRendersOriginBadgesAndTheNewsGap(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tx_news_domain_model_news.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        self::assertMatchesRegularExpression(
            '#<code>uid</code>\s*</td>\s*<td>\s*<span class="badge">default</span>#s',
            $body,
        );

        self::assertMatchesRegularExpression(
            '#Schema gaps \(runtime\).*?<li>.*?missing on.*?tx_news_domain_model_news.*?</li>#s',
            $body,
        );

        self::assertStringContainsString(
            'No indexing service configured for this record type.',
            $body,
        );
    }

    /**
     * Verifies selecting a specific record via selectedRecordUid actually
     * changes which record is assembled and shown, not just re-renders the
     * automatically-picked one. The pages fixture's uid=3 ("Second Page")
     * has the highest tstamp, so it is the automatic pick
     * (mostRecentlyChanged()); explicitly overriding to uid=2 ("First
     * Page") only proves the override took effect if the two differ, which
     * they do here.
     *
     * Also guards against a regression of the bug fixed in b5888ea: the
     * record-selector's Fluid f:if originally had no f:else branch, so
     * every non-matching <option> was rendered with a bare selected=""
     * attribute, which HTML treats as truthy regardless of its value,
     * marking every option "selected" at once. The plain
     * assertStringContainsString() on 'selected="selected"' above passes
     * identically whether that bug is present or fixed (both markups
     * contain that substring on the true branch), so it alone would not go
     * red on a revert. The assertStringNotContainsString() below does: the
     * old markup's non-matching options each carry a bare selected="",
     * which the fixed markup never emits. The substr_count() assertion is
     * not itself discriminating here (only the one matching option ever
     * carries the value "selected" in either markup), but documents the
     * cardinality invariant the fix establishes and would catch a future
     * regression that duplicates the selected value instead of leaving it
     * empty.
     */
    #[Test]
    public function indexActionUsesTheManuallySelectedRecordOverTheAutomaticOne(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject([
            'id'                => 0,
            'selectedRecordUid' => ['pages' => 2],
        ]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        self::assertStringContainsString(
            '<option value="2" selected="selected">2</option>',
            $body,
        );

        self::assertStringNotContainsString(
            'selected=""',
            $body,
        );

        self::assertSame(
            1,
            substr_count($body, 'selected="selected"'),
            'Exactly one <option> must carry selected="selected"; a re-introduced Fluid f:if without an f:else branch would mark every option selected at once.',
        );
    }

    /**
     * Guards against a regression of the bug fixed in f13dc65: buildSection()
     * unioned findRecordUidsInScope() across every indexing service
     * configured for a table, but always assembled the selected record under
     * indexingServices[0], the first configured service, regardless of which
     * service the record was actually found under.
     *
     * The pages fixtures here configure TWO indexing services with disjoint
     * scopes: "Page Indexer A" (uid 1, pages_doktype=1, no content elements)
     * only ever finds page uid 20, "Page Indexer B" (uid 2, pages_doktype=4,
     * WITH content elements) only ever finds page uid 21. Page 21 is
     * therefore reachable exclusively through service B, so its assembled
     * document only carries a 'content' attribute if it is genuinely
     * assembled under service B's isIncludeContentElements()=true
     * configuration (see UpdateAssembledPageDocumentEventListener). If the
     * f13dc65 bug were reintroduced, buildSection() would instead assemble
     * page 21 under indexingServices[0] (service A, includeContentElements
     * false), and the 'content' attribute would never appear, failing the
     * first assertion below.
     *
     * Selecting page 20 (only reachable through service A) is asserted not
     * to carry a 'content' attribute, both as the expected behaviour and as
     * a control: it shows the difference is genuinely driven by which
     * service backs each record, not by some unrelated always-on default.
     */
    #[Test]
    public function indexActionAssemblesUnderTheIndexingServiceTheSelectedRecordWasActuallyFoundUnder(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_multi_service.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_multi_service.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services_multi_service.csv');

        $responseForPageOnlyInServiceBsScope = $this->createDrivenSubject([
            'id'                => 0,
            'selectedRecordUid' => ['pages' => 21],
        ])->callIndexAction();

        $bodyForPageOnlyInServiceBsScope = (string) $responseForPageOnlyInServiceBsScope->getBody();

        self::assertSame(200, $responseForPageOnlyInServiceBsScope->getStatusCode());

        $pagesSectionForServiceBsRecord = $this->extractSectionHtml($bodyForPageOnlyInServiceBsScope, 'pages');

        self::assertStringContainsString(
            '<option value="21" selected="selected">21</option>',
            $pagesSectionForServiceBsRecord,
        );

        self::assertStringContainsString(
            '<code>content</code>',
            $pagesSectionForServiceBsRecord,
            'Page 21 is only in scope under "Page Indexer B" (includeContentElements=true); a "content" '
            . 'attribute must appear, proving assembly happened under that service, not indexingServices[0].',
        );

        $responseForPageOnlyInServiceAsScope = $this->createDrivenSubject([
            'id'                => 0,
            'selectedRecordUid' => ['pages' => 20],
        ])->callIndexAction();

        $bodyForPageOnlyInServiceAsScope = (string) $responseForPageOnlyInServiceAsScope->getBody();

        self::assertSame(200, $responseForPageOnlyInServiceAsScope->getStatusCode());

        $pagesSectionForServiceAsRecord = $this->extractSectionHtml($bodyForPageOnlyInServiceAsScope, 'pages');

        self::assertStringNotContainsString(
            '<code>content</code>',
            $pagesSectionForServiceAsRecord,
            'Page 20 is only in scope under "Page Indexer A" (includeContentElements=false); no "content" '
            . 'attribute must appear for it.',
        );
    }

    /**
     * Verifies the "indexing service configured but zero records currently
     * in scope" branch, distinct from "no indexing service configured at
     * all": the pages indexing service fixture here filters on
     * pages_doktype=99, which none of the imported pages fixture rows
     * (all doktype=1) match, so buildSection() must return an empty
     * recordUids list with noIndexingService=false, and the template must
     * render "No record of this type is currently in scope.", not "No
     * indexing service configured for this record type.".
     */
    #[Test]
    public function indexActionShowsNoRecordInScopeMessageWhenTheIndexingServiceMatchesNoRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_service_no_scope_match.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        $pagesSectionHtml = $this->extractSectionHtml($body, 'pages');

        self::assertStringContainsString(
            'No record of this type is currently in scope.',
            $pagesSectionHtml,
        );

        self::assertStringNotContainsString(
            'No indexing service configured for this record type.',
            $pagesSectionHtml,
        );
    }

    /**
     * Verifies an out-of-scope/invalid selectedRecordUid override (a UID
     * that is not part of the table's actual in-scope set) falls back to
     * the automatic mostRecentlyChanged() pick rather than erroring or
     * silently rendering an invalid selection. The pages fixture's uid=3
     * ("Second Page") has the highest tstamp, so it is the expected
     * automatic pick.
     */
    #[Test]
    public function indexActionFallsBackToTheAutomaticPickForAnOutOfScopeSelectedRecordUid(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject([
            'id'                => 0,
            'selectedRecordUid' => ['pages' => 99999],
        ]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        self::assertStringContainsString(
            '<option value="3" selected="selected">3</option>',
            $body,
        );

        self::assertStringNotContainsString(
            '<option value="99999"',
            $body,
        );
    }

    /**
     * Verifies the per-table try/catch in indexAction() (widened to catch
     * Throwable) genuinely isolates a single table's failed section build:
     * forces buildSection() to throw a plain \Error (not an \Exception, see
     * ThrowingForTableDocumentBuilder's docblock for why that distinction
     * is the whole point) for tt_content only, and asserts (a) tt_content's
     * own section shows the caught error message, and (b) pages' section,
     * in the exact same response, still renders normally, proving the
     * failure did not abort the whole module.
     */
    #[Test]
    public function indexActionIsolatesAPerTableFailureFromOtherTablesSections(): void
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

        $subject = $this->createDrivenSubject(['id' => 0], $throwingDocumentBuilder);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        $pagesSectionHtml     = $this->extractSectionHtml($body, 'pages');
        $ttContentSectionHtml = $this->extractSectionHtml($body, 'tt_content');

        self::assertStringContainsString(
            '<span class="badge">default</span>',
            $pagesSectionHtml,
            'A different, unaffected table\'s section must still render its normal content in the '
            . 'same response as a table whose section build threw.',
        );

        self::assertStringNotContainsString(
            'Failed to build this section',
            $pagesSectionHtml,
        );

        // Fluid HTML-encodes {section.error} by default, so the double
        // quotes around the table name in the message come back as &quot;.
        self::assertStringContainsString(
            'Failed to build this section: Simulated document assembly failure for table &quot;tt_content&quot;.',
            $ttContentSectionHtml,
        );
    }

    /**
     * Guards against a regression to one <f:form> per table: submits
     * selectedRecordUid overrides for TWO different tables (pages and
     * tt_content) in a single request and asserts both selections took
     * effect in the same response, plus that exactly one <form> tag is
     * rendered - the discriminating assertion, since a per-table form
     * would still let each individual override "work" (only the sibling
     * table's own override, submitted via a DIFFERENT form, would be lost
     * on submit, which a single-table assertion cannot detect).
     */
    #[Test]
    public function indexActionAppliesSelectedRecordOverridesForMultipleTablesFromASharedForm(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject([
            'id'                => 0,
            'selectedRecordUid' => [
                'pages'      => 2,
                'tt_content' => 1,
            ],
        ]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        $pagesSectionHtml     = $this->extractSectionHtml($body, 'pages');
        $ttContentSectionHtml = $this->extractSectionHtml($body, 'tt_content');

        self::assertStringContainsString(
            '<option value="2" selected="selected">2</option>',
            $pagesSectionHtml,
            'The pages selection must take effect even though tt_content was submitted in the same request.',
        );

        self::assertStringContainsString(
            '<option value="1" selected="selected">1</option>',
            $ttContentSectionHtml,
            'The tt_content selection must take effect even though pages was submitted in the same request.',
        );

        self::assertSame(
            1,
            substr_count($body, '<form'),
            'Exactly one <form> must wrap every table\'s section; one form per table would lose the '
            . 'other table\'s selection on submit.',
        );
    }

    /**
     * Proves mostRecentlyChanged()'s documented uid DESC tie-break is
     * actually load-bearing, not just present in the docblock: none of this
     * class's other automatic-pick tests exercise an actual tie, every one
     * of them uses attribute_overview_pages.csv, whose three pages all carry
     * distinct tstamps. This test uses a dedicated fixture
     * (attribute_overview_pages_tstamp_tie.csv) where pages uid=2 and uid=3
     * share the exact same tstamp (3000), both higher than the root page's
     * (uid=1, tstamp=1000). Without the uid DESC tie-break, which of the two
     * tied pages is picked would be whatever order the database happens to
     * return them in; with it, the higher uid (3) must always win.
     */
    #[Test]
    public function indexActionPicksTheHigherUidOnATstampTie(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_tstamp_tie.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        self::assertStringContainsString(
            '<option value="3" selected="selected">3</option>',
            $body,
        );
    }

    /**
     * Proves the "keep the first configured indexing service" tie-break in
     * buildSection() ($indexingServiceByRecordUid[$recordUid] ??=
     * $indexingService;) is genuinely load-bearing when two indexing
     * services for the SAME table have OVERLAPPING scopes and both match
     * the SAME record - unlike
     * indexActionAssemblesUnderTheIndexingServiceTheSelectedRecordWasActuallyFoundUnder()
     * above, whose two fixture services scope strictly DISJOINT pages (each
     * record reachable through exactly one service), so that test can never
     * exercise the ??= tie-break itself.
     *
     * The fixtures here configure both "Overlap Indexer A" (uid 1,
     * includeContentElements=false) and "Overlap Indexer B" (uid 2,
     * includeContentElements=true) with the IDENTICAL pages_recursive/
     * pages_doktype scope, so page uid 40 is genuinely in scope under BOTH.
     * Since findAllByTableName() returns the two services uid-ascending and
     * buildSection() processes them in that order, page 40 is recorded
     * under Service A on the first pass and Service B's later, redundant
     * match for the same UID is discarded by ??=. If the operator were a
     * plain = (last-service-wins) instead, page 40 would be assembled under
     * Service B (includeContentElements=true) and would carry a 'content'
     * attribute, flipping the outcome asserted below - this was spot-checked
     * by temporarily reverting ??= to = locally, which turns this
     * assertion red, confirming the test is sensitive to the exact
     * behaviour it documents.
     */
    #[Test]
    public function indexActionKeepsTheFirstConfiguredIndexingServiceWhenScopesOverlap(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_overlap.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_tt_content_overlap.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_services_overlap.csv');

        $subject = $this->createDrivenSubject([
            'id'                => 0,
            'selectedRecordUid' => ['pages' => 40],
        ]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        $pagesSectionHtml = $this->extractSectionHtml($body, 'pages');

        self::assertStringContainsString(
            '<option value="40" selected="selected">40</option>',
            $pagesSectionHtml,
        );

        self::assertStringNotContainsString(
            '<code>content</code>',
            $pagesSectionHtml,
            'Page 40 is in scope under BOTH overlapping indexing services; the FIRST configured one '
            . '("Overlap Indexer A", includeContentElements=false) must win, so no "content" attribute may '
            . 'appear. A reintroduced last-match-wins behaviour would instead assemble page 40 under '
            . '"Overlap Indexer B" (includeContentElements=true) and this assertion would fail.',
        );
    }

    /**
     * Proves AttributeOverviewModuleController::SCOPE_RECORD_LIMIT is
     * genuinely wired through to findRecordUidsInScope() end-to-end, not
     * only correct at the indexer level. AbstractIndexer-level tests already
     * cover findRecordUidsInScope($limit) capping/ordering correctly for
     * small test limits (2, 10), but nothing previously proved the
     * controller actually passes the real 200 value through, or that a
     * table with more than 200 in-scope records genuinely gets its
     * selector list capped at exactly 200 rather than silently unbounded.
     *
     * The pages fixture imports 205 in-scope records ("Page Indexer", no
     * other scope-narrowing filter), five more than the real limit, so an
     * unbounded/broken cap would materialize as a visibly different option
     * count (205, or 0 if the argument or the constant were accidentally
     * dropped/zeroed) rather than passing by coincidence.
     *
     * The expected count is read from the real
     * AttributeOverviewModuleController::SCOPE_RECORD_LIMIT constant via
     * ReflectionClass rather than a hardcoded "200" duplicate, so this
     * assertion cannot silently drift from the production value if the
     * constant is ever deliberately changed.
     */
    #[Test]
    public function indexActionCapsTheRecordSelectorAtTheRealScopeRecordLimit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_pages_scope_limit.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/attribute_overview_indexing_service_scope_limit.csv');

        $subject = $this->createDrivenSubject(['id' => 0]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        $pagesSectionHtml = $this->extractSectionHtml($body, 'pages');

        $scopeRecordLimit = (int) (new ReflectionClass(AttributeOverviewModuleController::class))
            ->getConstant('SCOPE_RECORD_LIMIT');

        self::assertSame(
            $scopeRecordLimit,
            substr_count($pagesSectionHtml, '<option value='),
            'The record selector must be capped at exactly SCOPE_RECORD_LIMIT '
            . '(205 in-scope records were made available), not left unbounded and not silently reduced.',
        );
    }

    /**
     * Drives indexAction() through the real DI-resolved TypoScriptService
     * (see createDrivenSubject()/createSubject(), no mock is substituted for
     * it) and asserts the rendered "Schema gaps (config)" section reflects
     * the REAL, bundled TypoScript field mapping
     * (Configuration/TypoScript/setup.typoscript), not merely that the
     * section exists. No database fixtures are imported: $fieldTargets[] in
     * indexAction() is populated from the TypoScript field mapping alone,
     * independent of whether any indexing service or record exists for a
     * table, so the config-gap comparison runs unconditionally for every
     * table getRecordTypes() returns.
     *
     * The bundled mapping targets 'subTitle' for both 'pages' (from
     * 'subtitle') and 'tt_content' (from 'subheader'), but neither
     * 'sys_file_metadata' nor 'tx_news_domain_model_news' maps any field to
     * 'subTitle' - a genuine, already-true config-level gap requiring no
     * fixture construction, only the actual shipped setup.typoscript. A
     * regression that breaks the real wiring (e.g. indexAction() passing an
     * empty array, or the runtime field mapping instead of the config one,
     * to detectConfigGaps()) would make this assertion fail, unlike a bare
     * "the section renders" check, which an always-empty configGaps would
     * already satisfy as simply absent (the surrounding f:if renders
     * nothing for an empty array).
     */
    #[Test]
    public function indexActionRendersConfigGapsFromTheRealTypoScriptFieldMapping(): void
    {
        $subject = $this->createDrivenSubject(['id' => 0]);

        $response = $subject->callIndexAction();
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());

        self::assertMatchesRegularExpression(
            '#Schema gaps \(config\).*?<code>subTitle</code>.*?present on.*?pages.*?tt_content.*?'
            . 'missing on.*?sys_file_metadata.*?tx_news_domain_model_news.*?</li>#s',
            $body,
        );
    }
}
