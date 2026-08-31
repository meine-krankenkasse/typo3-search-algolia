<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginResolverInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SchemaGapDetector;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;

use function array_column;
use function array_keys;
use function array_values;
use function in_array;
use function is_array;

/**
 * Backend module showing, per record type, every attribute sent to Algolia
 * for a representative record and its origin, plus cross-type schema gaps.
 *
 * This module itself never enqueues records, indexes anything, or writes to
 * the search engine or the database, it only reads. However, buildSection()
 * does run DocumentBuilder::assemble() as a dry run against a single
 * representative record per table, and assemble() dispatches the real
 * AfterDocumentAssembledEvent, the same event real indexing uses. Nothing
 * in that event's contract requires listeners to be side-effect-free, so a
 * third-party listener with a real side effect (an external API call, an
 * audit-log write, a cache invalidation) will also fire for real just from
 * an admin opening this diagnostic page. This is a known, accepted
 * characteristic of the preview mechanism, not a defect, it must simply not
 * be mistaken for "never triggers real indexing" in the literal sense.
 *
 * The record-type list is not hardcoded, it is derived live from
 * IndexerRegistry (see getRecordTypes()), the same registry the built-in
 * indexers populate themselves in ext_localconf.php, so this module only
 * ever attempts a table an indexer is actually registered for.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AttributeOverviewModuleController extends AbstractBaseModuleController
{
    /**
     * Maximum number of in-scope record UIDs buildSection() will
     * materialize per configured indexing service, to populate the record
     * selector dropdown and to bound mostRecentlyChanged()'s IN (...)
     * clause. This module is a diagnostic preview, not the real indexing
     * pipeline (see IndexerInterface::enqueueAll()), so it never needs the
     * full in-scope set, a low three-digit cap is more than enough to offer
     * a useful selection of candidate records without materializing the
     * same volume a real enqueueAll() run would on every page load.
     */
    private const int SCOPE_RECORD_LIMIT = 200;

    /**
     * @param ModuleTemplateFactory            $moduleTemplateFactory     Factory for creating module template instances
     * @param IconFactory                      $iconFactory               Factory for creating icon instances
     * @param IndexingServiceRepository        $indexingServiceRepository Repository for accessing indexing service configurations
     * @param ConnectionPool                   $connectionPool            The database connection pool, used to fetch the candidate/selected record
     * @param IndexerFactory                   $indexerFactory            Factory resolving a record type to its registered indexer instance
     * @param DocumentBuilder                  $documentBuilder           Builder used to run the real, write-free document assembly for the preview
     * @param AttributeOriginResolverInterface $attributeOriginResolver   Classifies each assembled field by its origin
     * @param SchemaGapDetector                $schemaGapDetector         Compares attribute-name sets across record types
     * @param TypoScriptServiceInterface       $typoScriptService         Field-mapping lookup, used for the config-level gap comparison
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        private readonly IndexingServiceRepository $indexingServiceRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly IndexerFactory $indexerFactory,
        private readonly DocumentBuilder $documentBuilder,
        private readonly AttributeOriginResolverInterface $attributeOriginResolver,
        private readonly SchemaGapDetector $schemaGapDetector,
        private readonly TypoScriptServiceInterface $typoScriptService,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $iconFactory,
        );
    }

    /**
     * Displays, per record type, every attribute sent to Algolia for a
     * representative record and its origin, plus cross-type schema gaps.
     *
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface
    {
        if (!$this->checkDatabaseAvailability()) {
            return $this->forwardErrorFlashMessage('error.databaseAvailability');
        }

        // The shared record-selector form is submitted via POST (see
        // Resources/Private/Templates/AttributeOverviewModule/Index.html for
        // why: a GET <f:form>'s "action" attribute bakes the page ID and the
        // backend route token into its query string, but per the HTML form
        // submission algorithm a GET submission discards that query string
        // entirely in favour of the form's own field values, silently
        // dropping both and triggering TYPO3's missing-token safety redirect
        // on every selector change). getParsedBody() is checked first and
        // getQueryParams() kept as a fallback for a plain GET-driven request
        // (e.g. a hand-built bookmark/link, or this action invoked directly
        // in a test), mirroring the same dual lookup
        // AbstractBaseModuleController::getPageId() already uses for 'id'.
        $parsedBody        = $this->request->getParsedBody();
        $selectedRecordUid = is_array($parsedBody) ? ($parsedBody['selectedRecordUid'] ?? null) : null;
        $selectedRecordUid ??= $this->request->getQueryParams()['selectedRecordUid'] ?? [];

        $sections     = [];
        $originMaps   = [];
        $fieldTargets = [];

        foreach ($this->getRecordTypes() as $table) {
            // Scoped per table rather than around the whole loop: a failure
            // building one table's section (e.g. a third-party
            // AfterDocumentAssembledEvent listener throwing while processing
            // that table) must not prevent every other, successfully built
            // table's diagnostic data from rendering.
            try {
                $section = $this->buildSection(
                    $table,
                    isset($selectedRecordUid[$table]) ? (int) $selectedRecordUid[$table] : null,
                );
            } catch (Throwable $exception) {
                $section = $this->errorSection($exception->getMessage());
            }

            $sections[$table]     = $section;
            $fieldTargets[$table] = array_values($this->typoScriptService->getFieldMappingByType($table));

            if ($section['originMap'] !== null) {
                $originMaps[$table] = $section['originMap'];
            }
        }

        $this->moduleTemplate->assign(
            'sections',
            $sections,
        );
        $this->moduleTemplate->assign(
            'runtimeGaps',
            $this->schemaGapDetector->detectRuntimeGaps($originMaps),
        );
        $this->moduleTemplate->assign(
            'configGaps',
            $this->schemaGapDetector->detectConfigGaps($fieldTargets),
        );
        // Carried into the shared record-selector form as a hidden 'id'
        // field (see Index.html), so the page context set by the module's
        // own page-tree navigation survives the form's POST round trip
        // instead of being silently dropped.
        $this->moduleTemplate->assign(
            'pageUid',
            $this->pageUid,
        );

        return $this->moduleTemplate->renderResponse('AttributeOverviewModule/Index');
    }

    /**
     * Returns the record types this module covers.
     *
     * Derived live from IndexerRegistry::getRegisteredIndexers() instead of a
     * hardcoded list, so it always matches the tables an indexer is actually
     * registered for. A hardcoded list would go stale (a new built-in
     * indexer added later would silently be missed) and, worse, a table
     * whose indexer class is registered only conditionally (e.g.
     * NewsIndexer, registered in ext_localconf.php only when EXT:news is
     * loaded) would otherwise always be attempted regardless of whether the
     * indexer implementation is actually available. In that case
     * buildSection() would report "no indexing service configured" for a
     * table that in fact has an indexing service row, it is just that no
     * indexer is registered for it, a misleading diagnosis. Deriving the
     * list live avoids ever attempting such a table in the first place.
     *
     * @return string[] The database table names covered by this module
     */
    private function getRecordTypes(): array
    {
        return array_column(
            IndexerRegistry::getRegisteredIndexers(),
            'tableName',
        );
    }

    /**
     * Builds the display section for one record type: resolves the in-scope
     * record UIDs, picks the automatic or manually-overridden record, runs
     * the dry-run assembly, and classifies the result.
     *
     * @param string   $table             The database table name
     * @param int|null $overrideRecordUid A manually selected record UID, if any
     *
     * @return array{recordUids: int[], selectedRecordUid: int|null, originMap: \MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap|null, noIndexingService: bool, error: string|null}
     */
    private function buildSection(string $table, ?int $overrideRecordUid): array
    {
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName($table)
            ->toArray();

        if ($indexingServices === []) {
            return $this->emptySection(true);
        }

        $indexer = $this->indexerFactory->makeInstanceByType($table);

        if (!($indexer instanceof IndexerInterface)) {
            return $this->emptySection(true);
        }

        // Record UID to the specific IndexingService it was actually found
        // under. A table can have more than one indexing service configured
        // (e.g. different pages_recursive/include_content_elements scopes),
        // and a record found under one service must later be assembled
        // under that SAME service, not an arbitrary/first one, otherwise the
        // attribute-origin output can reflect the wrong scope entirely.
        /** @var array<int, IndexingService> $indexingServiceByRecordUid */
        $indexingServiceByRecordUid = [];

        foreach ($indexingServices as $indexingService) {
            // Capped at SCOPE_RECORD_LIMIT: this is a diagnostic preview
            // populating a UI selector, not the real indexing pipeline
            // (IndexerInterface::enqueueAll() is unaffected, it never passes
            // a limit), so it never needs to materialize the full in-scope
            // set, which on a large table would be an unbounded, uncached DB
            // scan on every page load of this admin-only module.
            $scopedRecordUids = $indexer
                ->withIndexingService($indexingService)
                ->findRecordUidsInScope(self::SCOPE_RECORD_LIMIT);

            foreach ($scopedRecordUids as $recordUid) {
                // A UID can legitimately be in scope under more than one
                // indexing service; keep the first one found, deterministically.
                $indexingServiceByRecordUid[$recordUid] ??= $indexingService;
            }
        }

        if ($indexingServiceByRecordUid === []) {
            return $this->emptySection(false);
        }

        $recordUids = array_keys($indexingServiceByRecordUid);

        $selectedRecordUid = (($overrideRecordUid !== null) && in_array($overrideRecordUid, $recordUids, true))
            ? $overrideRecordUid
            : $this->mostRecentlyChanged($table, $recordUids);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

        $record = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $selectedRecordUid,
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        // The same indexer instance is re-scoped to the indexing service the
        // selected record was actually found under (tracked above), before
        // being handed to the builder, withIndexingService() is immutable,
        // the returned clone is what DocumentBuilder needs, not the
        // original, unscoped $indexer.
        $selectedIndexingService = $indexingServiceByRecordUid[$selectedRecordUid];

        $document = $this->documentBuilder
            ->setIndexer($indexer->withIndexingService($selectedIndexingService))
            ->setRecord($record !== false ? $record : [])
            ->setIndexingService($selectedIndexingService)
            ->assemble()
            ->getDocument();

        return [
            'recordUids'        => $recordUids,
            'selectedRecordUid' => $selectedRecordUid,
            'originMap'         => $this->attributeOriginResolver->resolve($document),
            'noIndexingService' => false,
            'error'             => null,
        ];
    }

    /**
     * Builds the empty-section array shared by every "nothing to show" path
     * in buildSection(): no indexing service configured at all, the
     * resolved indexer implementation is unavailable, or an indexing
     * service is configured but currently matches zero records.
     *
     * @param bool $noIndexingService Whether no indexing service is configured for this table at all
     *
     * @return array{recordUids: int[], selectedRecordUid: int|null, originMap: \MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap|null, noIndexingService: bool, error: string|null}
     */
    private function emptySection(bool $noIndexingService): array
    {
        return [
            'recordUids'        => [],
            'selectedRecordUid' => null,
            'originMap'         => null,
            'noIndexingService' => $noIndexingService,
            'error'             => null,
        ];
    }

    /**
     * Builds the section array for a table whose buildSection() call threw,
     * so the failure can be shown inline within that table's own section
     * instead of aborting the whole module (see indexAction()'s per-table
     * try/catch).
     *
     * @param string $errorMessage The caught exception's message
     *
     * @return array{recordUids: int[], selectedRecordUid: int|null, originMap: \MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap|null, noIndexingService: bool, error: string|null}
     */
    private function errorSection(string $errorMessage): array
    {
        return [
            'recordUids'        => [],
            'selectedRecordUid' => null,
            'originMap'         => null,
            'noIndexingService' => false,
            'error'             => $errorMessage,
        ];
    }

    /**
     * @param string $table      The database table name
     * @param int[]  $recordUids Candidate record UIDs
     *
     * @return int The most recently changed record UID among the candidates
     */
    private function mostRecentlyChanged(string $table, array $recordUids): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

        // Defensive fallback: every currently-registered indexer's table
        // defines ctrl.tstamp, so a table without one only occurs with a
        // future indexer. Covered by
        // AttributeOverviewModuleControllerTest::indexActionAutoPicksByUidDescendingWhenTheTablesTcaHasNoTstampField(),
        // which removes ctrl.tstamp from a real table's TCA at runtime and
        // asserts the auto-pick falls back to ordering by 'uid'.
        $tstampField = $GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? 'uid';

        $result = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $recordUids,
                ),
            )
            ->orderBy($tstampField, 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int) $result;
    }
}
