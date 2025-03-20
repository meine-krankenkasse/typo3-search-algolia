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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\PageRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use Override;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
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
    }

    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
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
                if (!in_array($file->getExtension(), $fileExtensions, true)) {
                    continue;
                }

                $metadata = $this->getMetadataFromFile($file);

                if ($metadata === []) {
                    continue;
                }

                // See UNIQUE column key in ext_tables.sql => table_name, record_uid, service_uid
                $uniqueKey = $this->getTable() . '-' . $metadata['uid'] . '-' . $serviceUid;

                // It's possible that a file appears multiple times in one or more collections. However,
                // the records are unique, so we don't need to add it again if it's already been queued.
                if (isset($items[$uniqueKey])) {
                    continue;
                }

                $items[$uniqueKey] = [
                    'table_name'  => $this->getTable(),
                    'record_uid'  => (int) $metadata['uid'],
                    'service_uid' => $serviceUid,
                    'changed'     => (int) ($GLOBALS['TCA'][$this->getTable()]['ctrl']['tstamp'] ?? 0),
                    'priority'    => 0,
                ];
            }
        }

        return $items;
    }

    /**
     * Returns the metadata record of the file.
     *
     * @param FileInterface $file
     *
     * @return array<string, int|float|string|null>
     */
    protected function getMetadataFromFile(FileInterface $file): array
    {
        if ($file instanceof File) {
            return $file->getMetaData()->get();
        }

        if (
            ($file instanceof FileReference)
            || ($file instanceof ProcessedFile)
        ) {
            return $file->getOriginalFile()->getMetaData()->get();
        }

        return [];
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
