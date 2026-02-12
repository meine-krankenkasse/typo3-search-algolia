<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\FileCollectionService;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use MeineKrankenkasse\Typo3SearchAlgolia\Traits\FileEligibilityTrait;
use Override;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Indexer for TYPO3 files and their metadata.
 *
 * This indexer is responsible for retrieving and processing files
 * from TYPO3 file collections for indexing in search engines. It handles:
 * - Filtering files by extension
 * - Checking if files are marked for indexing
 * - Processing file metadata
 * - Creating searchable documents from file records
 *
 * Files are important content assets in TYPO3 websites, including documents,
 * images, and other media that users may want to find through search.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class FileIndexer extends AbstractIndexer
{
    use FileEligibilityTrait;
    /**
     * The database table name for file metadata.
     *
     * This constant defines the TYPO3 database table that stores file metadata.
     * It is used throughout the indexer to identify which table to query.
     */
    public const string TABLE = 'sys_file_metadata';

    /**
     * Constructor for the file indexer.
     *
     * Initializes the indexer with all required dependencies for database access,
     * site handling, page operations, search engine creation, queue management,
     * document building, file collection handling, file operations, and TypoScript access.
     *
     * @param ConnectionPool           $connectionPool           Database connection pool for executing queries
     * @param SiteFinder               $siteFinder               Service for finding and handling TYPO3 sites
     * @param PageRepository           $pageRepository           Repository for page-related operations
     * @param SearchEngineFactory      $searchEngineFactory      Factory for creating search engine instances
     * @param QueueItemRepository      $queueItemRepository      Repository for managing indexing queue items
     * @param DocumentBuilder          $documentBuilder          Builder for creating document objects
     * @param FileCollectionRepository $fileCollectionRepository Repository for file collection operations
     * @param FileRepository           $fileRepository           Repository for file operations
     * @param TypoScriptService        $typoScriptService        Service for TypoScript configuration access
     * @param FileCollectionService    $fileCollectionService    Service for file collection operations
     */
    public function __construct(
        ConnectionPool $connectionPool,
        SiteFinder $siteFinder,
        PageRepository $pageRepository,
        SearchEngineFactory $searchEngineFactory,
        QueueItemRepository $queueItemRepository,
        DocumentBuilder $documentBuilder,
        private readonly ResourceFactory $resourceFactory,
        private readonly FileCollectionRepository $fileCollectionRepository,
        private readonly FileRepository $fileRepository,
        private readonly TypoScriptService $typoScriptService,
        private readonly FileCollectionService $fileCollectionService,
    ) {
        parent::__construct(
            $connectionPool,
            $siteFinder,
            $pageRepository,
            $searchEngineFactory,
            $queueItemRepository,
            $documentBuilder
        );
    }

    /**
     * Returns the database table name that this indexer is responsible for.
     *
     * This method implements the abstract method from AbstractIndexer and
     * returns the sys_file_metadata table name, which is where TYPO3 stores
     * metadata for files.
     *
     * @return string The database table name (sys_file_metadata)
     */
    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }

    /**
     * Returns the constraints used to query pages.
     *
     * This method overrides the parent implementation to return an empty array
     * because file indexing is not based on pages but on file collections.
     * Therefore, no page constraints are needed for this indexer.
     *
     * @param QueryBuilder $queryBuilder The query builder to use for creating expressions
     *
     * @return string[] An empty array as no page constraints are needed
     */
    #[Override]
    protected function getPagesQueryConstraint(QueryBuilder $queryBuilder): array
    {
        return [];
    }

    /**
     * Initializes a queue item record for indexing based on a given record UID.
     *
     * This method retrieves metadata associated with the specified record UID
     * and evaluates whether the corresponding file meets the criteria to be
     * enqueued for processing. The evaluation includes checks on file indexing
     * status, extension allowances, action permissions, metadata existence, and
     * belonging to specific file collections.
     *
     * @param int $recordUid The UID of the metadata record to initialize
     *
     * @return array<array-key, int|string>|false Returns an associative array with queue item details
     *                                            if the file can be enqueued, or false otherwise
     */
    #[Override]
    protected function initQueueItemRecord(int $recordUid): array|bool
    {
        // Get the file UID from the metadata record
        $fileUid = $this->fileRepository->getFileUidByMetadataUid($recordUid);

        if ($fileUid === false) {
            return false;
        }

        try {
            $file = $this->resourceFactory
                ->retrieveFileOrFolderObject((string) $fileUid);
        } catch (ResourceDoesNotExistException) {
            $file = null;
        }

        if (!($file instanceof File)) {
            return false;
        }

        if (!$this->isEligible($file, $this->typoScriptService->getAllowedFileExtensions())) {
            return false;
        }

        $collectionIds = GeneralUtility::intExplode(
            ',',
            $this->indexingService?->getFileCollections() ?? '',
            true
        );

        if (!$this->fileCollectionService->isInAnyCollection($file, $collectionIds)) {
            return false;
        }

        return [
            'table_name'  => $this->getTable(),
            'record_uid'  => $file->getMetaData()->offsetGet('uid'),
            'service_uid' => $this->indexingService?->getUid() ?? 0,
            'changed'     => (int) ($GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'] ?? 0),
            'priority'    => $this->getPriority(),
        ];
    }

    /**
     * Prepares file records for addition to the indexing queue.
     *
     * This method ov*errides the parent implementation to handle the specific
     * requirements of file indexing. Instead of querying a database table directly,
     * it retrieves files from file collections, filters them by extension and
     * indexability, and prepares them for indexing.
     *
     * @param int[] $recordUids Unused parameter, kept for compatibility with the parent method
     *
     * @return array<array-key, array<string, int|string>> Array of prepared file records
     */
    #[Override]
    protected function initQueueItemRecords(array $recordUids = []): array
    {
        $collectionIds = GeneralUtility::intExplode(
            ',',
            $this->indexingService?->getFileCollections() ?? '',
            true
        );

        $collections = $this
            ->fileCollectionRepository
            ->findAllByCollectionUids($collectionIds);

        $serviceUid = $this->indexingService?->getUid() ?? 0;
        $items      = [];

        foreach ($collections as $collection) {
            // Load content of the collection
            $collection->loadContents();

            /** @var File $file */
            foreach ($collection as $file) {
                if (!$this->isEligible($file, $this->typoScriptService->getAllowedFileExtensions())) {
                    continue;
                }

                $metadataUid = $file->getMetaData()->offsetGet('uid');

                // See UNIQUE column key in ext_tables.sql => table_name, record_uid, service_uid
                $uniqueKey = $this->getTable() . '-' . $metadataUid . '-' . $serviceUid;

                // It's possible that a file appears multiple times in one or more collections. However,
                // the records are unique, so we don't need to add it again if it's already been queued.
                if (isset($items[$uniqueKey])) {
                    continue;
                }

                $items[$uniqueKey] = [
                    'table_name'  => $this->getTable(),
                    'record_uid'  => $metadataUid,
                    'service_uid' => $serviceUid,
                    'changed'     => (int) ($GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'] ?? 0),
                    'priority'    => $this->getPriority(),
                ];
            }
        }

        return array_values($items);
    }
}
