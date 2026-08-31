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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginResolverInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SchemaGapDetector;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptServiceInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;

use function array_unique;
use function array_values;
use function in_array;

/**
 * Backend module showing, per record type, every attribute sent to Algolia
 * for a representative record and its origin, plus cross-type schema gaps.
 *
 * Read-only: never triggers real indexing, queueing, or index writes.
 * See docs/superpowers/specs/2026-08-28-attribute-overview-module-design.md.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AttributeOverviewModuleController extends AbstractBaseModuleController
{
    /**
     * The four record types this module covers, matching the tables the
     * built-in indexers register for in ext_localconf.php.
     *
     * @var string[]
     */
    private const array RECORD_TYPES = [
        'pages',
        'tt_content',
        'sys_file_metadata',
        'tx_news_domain_model_news',
    ];

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
            foreach (self::RECORD_TYPES as $table) {
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

        $recordUids = [];

        foreach ($indexingServices as $indexingService) {
            $recordUids = [
                ...$recordUids,
                ...$indexer->withIndexingService($indexingService)->findRecordUidsInScope(),
            ];
        }

        $recordUids = array_values(array_unique($recordUids));

        if ($recordUids === []) {
            return [
                'recordUids'        => [],
                'selectedRecordUid' => null,
                'originMap'         => null,
                'noIndexingService' => false,
            ];
        }

        $selectedRecordUid = ((null !== $overrideRecordUid) && in_array($overrideRecordUid, $recordUids, true))
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

        // The same indexer instance is re-scoped to the indexing service
        // whose row the selected record was ultimately found under
        // (indexingServices[0], consistent with how it was resolved above)
        // before being handed to the builder, withIndexingService() is
        // immutable, the returned clone is what DocumentBuilder needs, not
        // the original, unscoped $indexer.
        $document = $this->documentBuilder
            ->setIndexer($indexer->withIndexingService($indexingServices[0]))
            ->setRecord($record !== false ? $record : [])
            ->setIndexingService($indexingServices[0])
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
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int) $result;
    }
}
