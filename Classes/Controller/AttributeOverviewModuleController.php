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
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;

use function array_column;
use function array_key_exists;
use function array_keys;
use function implode;
use function is_array;
use function is_scalar;
use function mb_strlen;
use function mb_substr;

/**
 * Backend module showing one flat table, one row per attribute name sent to
 * Algolia, aggregated across every configured record type's automatically
 * picked, most-recently-changed record.
 *
 * Attribute presence is shown positively: a row's "occurrences" simply lists
 * every table that actually carries the attribute right now, on its own
 * automatically picked record. A table NOT appearing among a row's
 * occurrences already communicates "this table currently doesn't carry this
 * attribute", there is no separate negatively-framed "missing on" list. An
 * earlier design compared attribute-name sets across every table
 * symmetrically (SchemaGapDetector) and flagged every structural difference
 * as a "gap", which produced permanent, unfixable noise for record types
 * that are legitimately different by design (e.g. only file records ever
 * carry 'extension'/'mimeType'), so that comparison was dropped entirely in
 * favour of this table's positive framing.
 *
 * This module itself never enqueues records, indexes anything, or writes to
 * the search engine or the database, it only reads. However, buildTableAttributes()
 * does run DocumentBuilder::assemble() as a dry run against a single
 * representative record per table, and assemble() dispatches the real
 * AfterDocumentAssembledEvent, the same event real indexing uses. Nothing in
 * that event's contract requires listeners to be side-effect-free, so a
 * third-party listener with a real side effect (an external API call, an
 * audit-log write, a cache invalidation) will also fire for real just from
 * an admin opening this diagnostic page. This is a known, accepted
 * characteristic of the preview mechanism, not a defect, it must simply not
 * be mistaken for "never triggers real indexing" in the literal sense. It is
 * bounded to at most one dry-run assembly per table per page load, there is
 * no manual per-record selection any more, so this dispatch can no longer be
 * triggered repeatedly per user click as in an earlier design.
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
     * The table-status outcome for a table that built a representative
     * document successfully.
     */
    private const string STATUS_OK = 'ok';

    /**
     * No indexing service is configured for this table at all.
     */
    private const string STATUS_NO_INDEXING_SERVICE = 'no_indexing_service';

    /**
     * An indexing service is configured for this table, but it currently
     * matches zero in-scope records.
     */
    private const string STATUS_NO_RECORD_IN_SCOPE = 'no_record_in_scope';

    /**
     * Building this table's preview document threw.
     */
    private const string STATUS_ERROR = 'error';

    /**
     * An example value longer than this many characters is truncated with a
     * trailing ellipsis marker, so one outsized value (e.g. the 'content'
     * field's full aggregated page text) cannot dominate the table.
     */
    private const int EXAMPLE_VALUE_MAX_LENGTH = 150;

    /**
     * Maximum number of in-scope record UIDs buildTableAttributes() will
     * materialize per configured indexing service, to bound
     * mostRecentlyChanged()'s IN (...) clause. This module is a diagnostic
     * preview, not the real indexing pipeline (see IndexerInterface::enqueueAll()),
     * so it never needs the full in-scope set, a low three-digit cap is more
     * than enough to reliably find the most recently changed candidate.
     *
     * This caps the SQL-level result for every indexer whose
     * findRecordUidsInScope() pushes the limit down to the database (see
     * AbstractIndexer::fetchRecords()'s ORDER BY ... LIMIT), which is every
     * indexer except FileIndexer: FAL's File/FileCollection API exposes no
     * such SQL-level ordering/capping primitive, so FileIndexer::
     * initQueueItemRecords() must materialize every eligible file across
     * every configured file collection before this limit is applied
     * in-memory (see that method's own docblock). For the file table this
     * constant therefore bounds the returned set, not the scanned one, and
     * buildTableAttributes() pays that full scan once per configured
     * file-type indexing service, not once per page load.
     *
     * The SQL-backed indexers (pages, tt_content, and tx_news_domain_model_news
     * when EXT:news is loaded) push the ORDER BY ... LIMIT to the database.
     * For pages/tt_content specifically, TYPO3 core's own schema carries no
     * index on ctrl.tstamp, so the database still has to filesort the full
     * WHERE-matched (pre-LIMIT) set server-side, on a large recursive
     * page-tree scope, before this constant discards the rest. Adding a
     * supporting index is not this extension's call to make, pages/
     * tt_content are TYPO3 core tables, not owned by this extension's
     * schema. EXT:news's own schema is a third-party dependency not
     * verified here. Accepted the same way as the FileIndexer trade-off
     * above, this diagnostic module is admin-only and opened rarely, not
     * the real indexing pipeline.
     */
    private const int SCOPE_RECORD_LIMIT = 200;

    /**
     * @param ModuleTemplateFactory            $moduleTemplateFactory     Factory for creating module template instances
     * @param IconFactory                      $iconFactory               Factory for creating icon instances
     * @param IndexingServiceRepository        $indexingServiceRepository Repository for accessing indexing service configurations
     * @param ConnectionPool                   $connectionPool            The database connection pool, used to fetch the representative record
     * @param IndexerFactory                   $indexerFactory            Factory resolving a record type to its registered indexer instance
     * @param DocumentBuilder                  $documentBuilder           Builder used to run the real, write-free document assembly for the preview
     * @param AttributeOriginResolverInterface $attributeOriginResolver   Classifies each assembled field by its origin
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        private readonly IndexingServiceRepository $indexingServiceRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly IndexerFactory $indexerFactory,
        private readonly DocumentBuilder $documentBuilder,
        private readonly AttributeOriginResolverInterface $attributeOriginResolver,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $iconFactory,
        );
    }

    /**
     * Displays one flat table, one row per attribute name, aggregated across
     * every configured record type's automatically picked, most-recently-
     * changed record, plus a short status list for every table that
     * currently has nothing to preview.
     *
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface
    {
        if (!$this->checkDatabaseAvailability()) {
            return $this->forwardErrorFlashMessage('error.databaseAvailability');
        }

        $recordTypes   = $this->getRecordTypes();
        $tableStatuses = [];
        $attributeRows = [];

        foreach ($recordTypes as $table) {
            // Scoped per table rather than around the whole loop: a failure
            // building one table's preview (e.g. a third-party
            // AfterDocumentAssembledEvent listener throwing while processing
            // that table) must not prevent every other, successfully built
            // table's attributes from appearing.
            try {
                $result = $this->buildTableAttributes($table);
            } catch (Throwable $exception) {
                $tableStatuses[$table] = $this->tableStatus(
                    self::STATUS_ERROR,
                    $exception->getMessage(),
                );

                continue;
            }

            if ($result['status'] !== self::STATUS_OK) {
                $tableStatuses[$table] = $this->tableStatus($result['status']);

                continue;
            }

            $attributeRows = $this->mergeTableAttributes(
                $attributeRows,
                $table,
                $result['originDetails'],
                $result['fields'],
            );
        }

        $this->moduleTemplate->assignMultiple([
            'recordTypes'   => $recordTypes,
            'tableStatuses' => $tableStatuses,
            'attributeRows' => $attributeRows,
        ]);

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
     * buildTableAttributes() would report "no indexing service configured"
     * for a table that in fact has an indexing service row, it is just that
     * no indexer is registered for it, a misleading diagnosis. Deriving the
     * list live avoids ever attempting such a table in the first place.
     *
     * Also the order every aggregation in this module treats as "first
     * table registered", see mergeTableAttributes()'s exampleValue rule.
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
     * Builds one table's contribution to the aggregation: resolves the
     * in-scope record UIDs, picks the automatically most-recently-changed
     * record, runs the dry-run assembly, and classifies the result.
     *
     * @param string $table The database table name
     *
     * @return array{status: string, originDetails: array<string, array{origin: string, detail: string|null}>, fields: array<string, mixed>}
     */
    private function buildTableAttributes(string $table): array
    {
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName($table)
            ->toArray();

        if ($indexingServices === []) {
            return $this->emptyTableAttributes(self::STATUS_NO_INDEXING_SERVICE);
        }

        $indexer = $this->indexerFactory->makeInstanceByType($table);

        if (!($indexer instanceof IndexerInterface)) {
            return $this->emptyTableAttributes(self::STATUS_NO_INDEXING_SERVICE);
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
            // Capped at SCOPE_RECORD_LIMIT: this is a diagnostic preview, not
            // the real indexing pipeline (IndexerInterface::enqueueAll() is
            // unaffected, it never passes a limit), so it never needs to
            // materialize the full in-scope set, which on a large table
            // would be an unbounded, uncached DB scan on every page load of
            // this admin-only module.
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
            return $this->emptyTableAttributes(self::STATUS_NO_RECORD_IN_SCOPE);
        }

        $recordUids          = array_keys($indexingServiceByRecordUid);
        $mostRecentRecordUid = $this->mostRecentlyChanged(
            $table,
            $recordUids,
        );

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

        $record = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $mostRecentRecordUid,
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        // The record picked by mostRecentlyChanged() a moment ago can still
        // have vanished by the time this select runs (deleted concurrently,
        // or a custom findRecordUidsInScope() implementation returning a
        // stale UID). DocumentBuilder::assemble() reads $record['uid']
        // unconditionally, so passing an empty array through would surface
        // as a PHP warning instead of the same "nothing to preview" outcome
        // an empty scope already produces.
        if ($record === false) {
            return $this->emptyTableAttributes(self::STATUS_NO_RECORD_IN_SCOPE);
        }

        // The same indexer instance is re-scoped to the indexing service the
        // picked record was actually found under (tracked above), before
        // being handed to the builder, withIndexingService() is immutable,
        // the returned clone is what DocumentBuilder needs, not the
        // original, unscoped $indexer.
        $selectedIndexingService = $indexingServiceByRecordUid[$mostRecentRecordUid];

        $document = $this->documentBuilder
            ->setIndexer($indexer->withIndexingService($selectedIndexingService))
            ->setRecord($record)
            ->setIndexingService($selectedIndexingService)
            ->assemble()
            ->getDocument();

        return [
            'status'        => self::STATUS_OK,
            'originDetails' => $this->attributeOriginResolver->resolve($document)->getOriginDetails(),
            'fields'        => $document->getFields(),
        ];
    }

    /**
     * Builds the empty result array shared by every "nothing to preview"
     * outcome buildTableAttributes() can return: no indexing service
     * configured at all, or the resolved indexer implementation is
     * unavailable (both map to STATUS_NO_INDEXING_SERVICE), and an indexing
     * service is configured but currently matches zero records, or the
     * one record picked from that scope no longer exists by the time it
     * is fetched (both map to STATUS_NO_RECORD_IN_SCOPE).
     *
     * @param string $status One of the STATUS_* constants except STATUS_OK
     *
     * @return array{status: string, originDetails: array<string, array{origin: string, detail: string|null}>, fields: array<string, mixed>}
     */
    private function emptyTableAttributes(string $status): array
    {
        return [
            'status'        => $status,
            'originDetails' => [],
            'fields'        => [],
        ];
    }

    /**
     * Builds one entry of the table-status list rendered for every table
     * that has nothing to preview.
     *
     * @param string      $type    One of the STATUS_* constants except STATUS_OK
     * @param string|null $message The caught exception's message, only set for STATUS_ERROR
     *
     * @return array{type: string, message: string|null}
     */
    private function tableStatus(string $type, ?string $message = null): array
    {
        return [
            'type'    => $type,
            'message' => $message,
        ];
    }

    /**
     * Merges one table's classified attributes into the running, flat,
     * cross-table aggregation, keyed by attribute name.
     *
     * The exampleValue is taken from the FIRST table (in getRecordTypes()'s
     * own registration order, since indexAction() processes tables in that
     * order and calls this method at most once per table) whose
     * contribution to a given attribute name yields a non-empty value, an
     * already populated exampleValue for a name is never overwritten by a
     * later table's value for the same name. A table whose own value is
     * empty (e.g. formatExampleValue() falling back to '') does not count
     * as "populated", so a later table's genuinely non-empty value for the
     * same name is never permanently suppressed by an earlier table's blank
     * one - see this extension's own Configuration/TypoScript/setup.
     * typoscript, where both pages.subtitle and tt_content.subheader map to
     * the same 'subTitle' target attribute.
     *
     * @param array<string, array{occurrences: array<string, array{origin: string, detail: string|null}>, exampleValue: string}> $attributeRows The aggregation built so far
     * @param string                                                                                                             $table         The database table name this contribution came from
     * @param array<string, array{origin: string, detail: string|null}>                                                          $originDetails One table's attribute name to {origin, detail} pairs, see AttributeOriginMap::getOriginDetails()
     * @param array<string, mixed>                                                                                               $fields        The same table's assembled document fields, see Document::getFields()
     *
     * @return array<string, array{occurrences: array<string, array{origin: string, detail: string|null}>, exampleValue: string}> The aggregation with this table's contribution merged in
     */
    private function mergeTableAttributes(array $attributeRows, string $table, array $originDetails, array $fields): array
    {
        foreach ($originDetails as $attributeName => $originDetail) {
            $attributeRows[$attributeName]['occurrences'][$table] = $originDetail;

            $existingExampleValue = $attributeRows[$attributeName]['exampleValue'] ?? '';

            $attributeRows[$attributeName]['exampleValue'] = $existingExampleValue !== ''
                ? $existingExampleValue
                : $this->formatExampleValue(
                    $fields,
                    $attributeName,
                );
        }

        return $attributeRows;
    }

    /**
     * Formats one document field's raw value for safe, readable display in
     * the attribute overview table: an array (e.g. the 'categories' field, a
     * string[]) is comma-joined, any other scalar is cast to a string, and
     * the result is truncated with a trailing ellipsis marker if it exceeds
     * EXAMPLE_VALUE_MAX_LENGTH characters, so one outsized value (e.g. the
     * 'content' field's full aggregated page text) cannot dominate the
     * table. Fluid's default auto-escaping already handles HTML-safety on
     * output, this method does not pre-escape.
     *
     * @param array<string, mixed> $fields        The assembled document's fields, see Document::getFields()
     * @param string               $attributeName The field name to format
     *
     * @return string The formatted, display-ready example value
     */
    private function formatExampleValue(array $fields, string $attributeName): string
    {
        // Guards against an AttributeOriginResolverInterface implementation
        // (a documented public-API extension point, see that interface's own
        // docblock) classifying an attribute name not present in this same
        // document's own fields - nothing in the interface's contract rules
        // that out, only the one shipped resolver's own behaviour does.
        if (!array_key_exists($attributeName, $fields)) {
            return '';
        }

        $value = $fields[$attributeName];

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $parts[] = is_scalar($item) ? (string) $item : '';
            }

            $stringValue = implode(
                ', ',
                $parts,
            );
        } elseif (is_scalar($value)) {
            $stringValue = (string) $value;
        } else {
            $stringValue = '';
        }

        if (mb_strlen($stringValue) > self::EXAMPLE_VALUE_MAX_LENGTH) {
            return mb_substr(
                $stringValue,
                0,
                self::EXAMPLE_VALUE_MAX_LENGTH,
            ) . '…';
        }

        return $stringValue;
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
        // AttributeOverviewModuleControllerTest::attributeOverviewAutoPicksByUidDescendingWhenTheTablesTcaHasNoTstampField(),
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
