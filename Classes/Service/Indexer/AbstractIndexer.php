<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Indexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

use function count;
use function is_array;

/**
 * Class AbstractIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractIndexer implements IndexerInterface
{
    /**
     * @var ConnectionPool
     */
    protected ConnectionPool $connectionPool;

    /**
     * @var SiteFinder
     */
    protected SiteFinder $siteFinder;

    /**
     * @var PageRepository
     */
    protected PageRepository $pageRepository;

    /**
     * @var QueueItemRepository
     */
    private QueueItemRepository $queueItemRepository;

    /**
     * @var string
     */
    private string $title = '';

    /**
     * @var string
     */
    private string $icon = '';

    /**
     * Constructor.
     *
     * @param ConnectionPool      $connectionPool
     * @param SiteFinder          $siteFinder
     * @param PageRepository      $pageRepository
     * @param QueueItemRepository $queueItemRepository
     */
    public function __construct(
        ConnectionPool $connectionPool,
        SiteFinder $siteFinder,
        PageRepository $pageRepository,
        QueueItemRepository $queueItemRepository,
    ) {
        $this->connectionPool = $connectionPool;
        $this->siteFinder     = $siteFinder;
        $this->pageRepository = $pageRepository;
        $this->queueItemRepository = $queueItemRepository;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title
     *
     * @return AbstractIndexer
     */
    public function setTitle(string $title): AbstractIndexer
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string
     */
    public function getIcon(): string
    {
        return $this->icon;
    }

    /**
     * @param string $icon
     *
     * @return AbstractIndexer
     */
    public function setIcon(string $icon): AbstractIndexer
    {
        $this->icon = $icon;

        return $this;
    }

    public function enqueue(): int
    {
        $this->queueItemRepository
            ->deleteByType($this->getType());

        return $this->queueItemRepository
            ->bulkInsert(
                $this->queryItems()
            );
    }

    public function dequeue(): void
    {
    }

    public function indexRecord(Indexer $indexer, array $record): bool
    {
        $searchEngineService = $this->getSearchEngineService(
            $indexer->getSearchEngine()->getEngine()
        );

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return false;
        }

        $searchEngineService->indexOpen(
            $indexer->getSearchEngine()->getIndexName()
        );

        /** @var Document $document */
        $document = GeneralUtility::makeInstance(Document::class);

        // Fill the document with configured fields for each type
        $this->addRecordFieldsToDocument($indexer, $document, $record);

        // var_dump($document);
        // exit;

        $result = $searchEngineService->documentUpdate($document);

        $searchEngineService->indexCommit();
        $searchEngineService->indexClose();

        return $result;
    }

    /**
     * @param Indexer  $indexer
     * @param Document $document
     * @param array    $record
     *
     * @return void
     */
    private function addRecordFieldsToDocument(Indexer $indexer, Document $document, array $record): void
    {
        $typoscriptConfiguration = $this->getTypoScriptConfiguration();

        foreach ($record as $fieldName => $recordValue) {
            if (!isset($typoscriptConfiguration['indexer'][$indexer->getType()][$fieldName])) {
                continue;
            }

            $fieldName  = $typoscriptConfiguration['indexer'][$indexer->getType()][$fieldName];
            $fieldValue = $this->resolveFieldValue($indexer, $fieldName, $recordValue);

            // Ignore empty field values
            if (($fieldValue === null)
                || ($fieldValue === '')
                || (is_array($fieldValue) && empty($fieldValue))
            ) {
                continue;
            }

            $document->setField(
                $fieldName,
                $fieldValue
            );
        }
    }

    /**
     * @param int|string $key
     * @param mixed      $value
     *
     * @return array|float|int|string|null
     */
    private function resolveFieldValue(Indexer $indexer, int|string $key, mixed $value): array|float|int|string|null
    {
        return $value;
    }

    private function getTypoScriptConfiguration(): array
    {
        $configurationManager    = GeneralUtility::makeInstance(ConfigurationManager::class);
        $typoscriptConfiguration = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
            Constants::EXTENSION_NAME
        );

        return GeneralUtility::removeDotsFromTS($typoscriptConfiguration)['module']['tx_typo3searchalgolia'];
    }

    /**
     * @param string $subtype
     *
     * @return SearchEngineInterface|null
     */
    private function getSearchEngineService(string $subtype): ?SearchEngineInterface
    {
        foreach ($GLOBALS['T3_SERVICES']['mkk_search_engine'] as $service) {
            if ($service['serviceType'] !== 'mkk_search_engine') {
                continue;
            }

            if ($service['subtype'] !== $subtype) {
                continue;
            }

            return GeneralUtility::makeInstance(
                $service['className']
            );
        }

        return null;
    }

    /**
     * Returns records from the current indexer table matching certain constraints.
     *
     * @return array<int, array<string, int|string>>
     *
     * @throws Exception
     */
    private function queryItems(): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($this->getTable());

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DefaultRestrictionContainer::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class));

        $constraints = [];

        if ($this->getType() === PageIndexer::TYPE) {
            $constraints[] = $queryBuilder->expr()->in(
                'uid',
                $this->getPages(),
            );
        } else {
            $constraints[] = $queryBuilder->expr()->in(
                'pid',
                $this->getPages(),
            );
        }

        // Add indexer related constraints
        $constraints = array_merge(
            $constraints,
            $this->getIndexerConstraints()
        );

        return $queryBuilder
            ->select(
                'uid AS record_uid',
            )
            ->addSelectLiteral(
                '\'' . $this->getTable() . '\' as table_name',
                '\'' . $this->getType() . '\' AS indexer_type',
                $this->getChangedFieldStatement() . ' AS changed',
                '0 AS priority'
            )
            ->from($this->getTable())
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Returns all page UIDs of all sites.
     *
     * @return int[]
     */
    private function getPages(): array
    {
        $sites   = $this->siteFinder->getAllSites(false);
        $pageIds = [[]];

        // TODO Limit to single sites (configurable)?
        foreach ($sites as $site) {
            $pageIds[] = $this->pageRepository->getPageIdsRecursive(
                [
                    $site->getRootPageId(),
                ],
                9999
            );
        }

        return array_merge(...$pageIds);
    }

    /**
     * Returns the SQL select statement to determine the latest change timestamp.
     *
     * @return string
     */
    private function getChangedFieldStatement(): string
    {
        if (!empty($GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime'])) {
            return 'GREATEST(' . $GLOBALS['TCA'][$this->getTable()]['ctrl']['enablecolumns']['starttime']
                . ', ' . $GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'] . ')';
        }

        return $GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'];
    }
}
