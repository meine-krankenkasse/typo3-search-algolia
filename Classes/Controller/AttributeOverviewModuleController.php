<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use Exception;
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
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;

use function array_column;
use function array_keys;
use function array_values;
use function in_array;

/**
 * Backend module showing, per record type, every attribute sent to Algolia
 * for a representative record and its origin, plus cross-type schema gaps.
 *
 * Read-only: never triggers real indexing, queueing, or index writes. It
 * only runs DocumentBuilder::assemble() as a dry run against a single
 * representative record per table and reads back the result, it never
 * enqueues, indexes, or writes to the search engine or the database.
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

        $selectedRecordUid = $this->request->getQueryParams()['selectedRecordUid'] ?? [];

        $sections     = [];
        $originMaps   = [];
        $fieldTargets = [];

        try {
            foreach ($this->getRecordTypes() as $table) {
                $section = $this->buildSection(
                    $table,
                    isset($selectedRecordUid[$table]) ? (int) $selectedRecordUid[$table] : null,
                );

                $sections[$table]     = $section;
                $fieldTargets[$table] = array_values($this->typoScriptService->getFieldMappingByType($table));

                if ($section['originMap'] !== null) {
                    $originMaps[$table] = $section['originMap'];
                }
            }
        } catch (Exception $exception) {
            return $this->forwardExceptionFlashMessage($exception);
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
     * @return array{recordUids: int[], selectedRecordUid: int|null, originMap: \MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap|null, noIndexingService: bool}
     */
    private function buildSection(string $table, ?int $overrideRecordUid): array
    {
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName($table)
            ->toArray();

        if ($indexingServices === []) {
            return [
                'recordUids'        => [],
                'selectedRecordUid' => null,
                'originMap'         => null,
                'noIndexingService' => true,
            ];
        }

        $indexer = $this->indexerFactory->makeInstanceByType($table);

        if (!($indexer instanceof IndexerInterface)) {
            return [
                'recordUids'        => [],
                'selectedRecordUid' => null,
                'originMap'         => null,
                'noIndexingService' => true,
            ];
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
            return [
                'recordUids'        => [],
                'selectedRecordUid' => null,
                'originMap'         => null,
                'noIndexingService' => false,
            ];
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
