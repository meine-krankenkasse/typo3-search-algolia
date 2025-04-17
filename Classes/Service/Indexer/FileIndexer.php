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
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\FileHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use Override;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

use function in_array;
use function is_string;

/**
 * Class FileIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class FileIndexer extends AbstractIndexer
{
    public const string TABLE = 'sys_file_metadata';

    /**
     * @var ConfigurationManagerInterface
     */
    private readonly ConfigurationManagerInterface $configurationManager;

    /**
     * @var FileCollectionRepository
     */
    private readonly FileCollectionRepository $fileCollectionRepository;

    /**
     * @var FileHandler
     */
    private readonly FileHandler $fileHandler;

    /**
     * Constructor.
     *
     * @param ConnectionPool                $connectionPool
     * @param SiteFinder                    $siteFinder
     * @param PageRepository                $pageRepository
     * @param SearchEngineFactory           $searchEngineFactory
     * @param QueueItemRepository           $queueItemRepository
     * @param DocumentBuilder               $documentBuilder
     * @param ConfigurationManagerInterface $configurationManager
     * @param FileCollectionRepository      $fileCollectionRepository
     * @param FileHandler                   $fileHandler
     */
    public function __construct(
        ConnectionPool $connectionPool,
        SiteFinder $siteFinder,
        PageRepository $pageRepository,
        SearchEngineFactory $searchEngineFactory,
        QueueItemRepository $queueItemRepository,
        DocumentBuilder $documentBuilder,
        ConfigurationManagerInterface $configurationManager,
        FileCollectionRepository $fileCollectionRepository,
        FileHandler $fileHandler,
    ) {
        parent::__construct(
            $connectionPool,
            $siteFinder,
            $pageRepository,
            $searchEngineFactory,
            $queueItemRepository,
            $documentBuilder
        );

        $this->configurationManager     = $configurationManager;
        $this->fileCollectionRepository = $fileCollectionRepository;
        $this->fileHandler              = $fileHandler;
    }

    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }

    /**
     * Returns the constraints used to query pages.
     *
     * @param QueryBuilder $queryBuilder
     *
     * @return string[]
     */
    #[Override]
    protected function getPagesQueryConstraint(QueryBuilder $queryBuilder): array
    {
        return [];
    }

    /**
     * Returns records from the current indexer table matching certain constraints.
     *
     * @return array<array-key, array<string, int|string>>
     */
    #[Override]
    protected function initQueueItemRecords(): array
    {
        $collectionIds = GeneralUtility::intExplode(
            ',',
            $this->indexingService?->getFileCollections() ?? '',
            true
        );

        $collections = $this
            ->fileCollectionRepository
            ->findAllByCollections($collectionIds);

        $fileExtensions = $this->getAllowedFileExtensions();
        $serviceUid     = $this->indexingService?->getUid() ?? 0;
        $items          = [];

        foreach ($collections as $collection) {
            // Load content of the collection
            $collection->loadContents();

            /** @var File $file */
            foreach ($collection as $file) {
                if (!$this->isExtensionAllowed($file, $fileExtensions)) {
                    continue;
                }

                if (!$this->isIndexable($file)) {
                    continue;
                }

                $metadataUid = $this->fileHandler->getMetadataUid($file);

                if ($metadataUid === false) {
                    continue;
                }

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

        return $items;
    }

    /**
     * Returns TRUE if the file extension of the specified file belongs to the list of allowed file extensions.
     *
     * @param FileInterface $file
     * @param string[]      $fileExtensions
     *
     * @return bool
     */
    private function isExtensionAllowed(FileInterface $file, array $fileExtensions): bool
    {
        return in_array($file->getExtension(), $fileExtensions, true);
    }

    /**
     * Returns TRUE if the file is not excluded from indexing.
     *
     * @param FileInterface $file
     *
     * @return bool
     */
    private function isIndexable(FileInterface $file): bool
    {
        return $file->hasProperty('no_search')
            && ((int) $file->getProperty('no_search') === 0);
    }

    /**
     * Returns the configured file extensions.
     *
     * @return string[]
     */
    private function getAllowedFileExtensions(): array
    {
        $indexerType             = $this->getTable();
        $typoscriptConfiguration = $this->getTypoScriptConfiguration();

        if (
            !isset($typoscriptConfiguration['indexer'][$indexerType]['extensions'])
            || !is_string($typoscriptConfiguration['indexer'][$indexerType]['extensions'])
        ) {
            return [];
        }

        return GeneralUtility::trimExplode(
            ',',
            $typoscriptConfiguration['indexer'][$indexerType]['extensions'],
            true
        );
    }

    /**
     * Returns the TypoScript configuration of the extension.
     *
     * @return array<string, array<string, array<string, string|array<string, string>>>>
     */
    private function getTypoScriptConfiguration(): array
    {
        $typoscriptConfiguration = $this->configurationManager
            ->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
                Constants::EXTENSION_NAME
            );

        return GeneralUtility::removeDotsFromTS($typoscriptConfiguration)['module']['tx_typo3searchalgolia'];
    }
}
