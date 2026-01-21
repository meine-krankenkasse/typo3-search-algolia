<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\ContextMenu\ItemProviders;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Repository\FileCollectionRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use Override;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Resource\Collection\FolderBasedFileCollection;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;

/**
 * This class provides context menu items for files in the TYPO3 backend.
 *
 * It extends the TYPO3 AbstractProvider to add custom context menu options
 * specifically for the Algolia search indexing functionality. The provider
 * adds an option to enqueue individual files for indexing directly from
 * the file list view, making it easier for editors to update search indexes
 * for specific files without having to use the full indexing interface.
 *
 * The provider checks file permissions, file types, and user access rights
 * to determine whether the indexing options should be displayed for a
 * particular file.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueProvider extends AbstractProvider
{
    /**
     * The current file or folder object being processed.
     *
     * This property stores the file or folder object that the context menu
     * is being generated for. It's initialized in the initialize() method
     * and used throughout the class to determine what actions are available.
     *
     * @var File|Folder|null
     */
    private Folder|File|null $record = null;

    /**
     * Configuration for the context menu items provided by this class.
     *
     * This array defines the structure and behavior of the context menu items
     * that this provider adds to the TYPO3 backend. Each item has a type,
     * label, icon, and callback action that determines what happens when
     * the item is clicked.
     *
     * @var array<string, array<string, string>>
     */
    protected $itemsConfiguration = [
        'divider1' => [
            'type' => 'divider',
        ],
        'algolia_enqueue_one' => [
            'label'          => 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:context-menu.enqueueOne',
            'iconIdentifier' => 'actions-file-add',
            'callbackAction' => 'enqueueOne',
        ],
    ];

    /**
     * Constructor.
     *
     * @param FileCollectionRepository  $fileCollectionRepository
     * @param IndexingServiceRepository $indexingServiceRepository
     */
    public function __construct(
        private ResourceFactory $resourceFactory,
        private TypoScriptService $typoScriptService,
        private UriBuilder $uriBuilder,
        private FileCollectionRepository $fileCollectionRepository,
        private IndexingServiceRepository $indexingServiceRepository,
    ) {
        parent::__construct();
    }

    /**
     * Determines if this provider can handle the current context menu request.
     *
     * This method checks if the current table is 'sys_file', which indicates
     * that the context menu is being generated for a file in the file list.
     * Only if this condition is met will this provider add its custom items
     * to the context menu.
     *
     * @return bool True if this provider can handle the current context menu request, false otherwise
     */
    #[Override]
    public function canHandle(): bool
    {
        return $this->table === 'sys_file';
    }

    /**
     * Initializes the file or folder object for the current context menu item.
     *
     * This method retrieves the file or folder object that corresponds to the
     * identifier provided in the context menu request. It uses TYPO3's ResourceFactory
     * to convert the identifier into a proper File or Folder object that can be
     * used to determine available actions and permissions.
     *
     * If the file or folder cannot be found (e.g., if it has been deleted),
     * the record property is set to null to prevent errors.
     */
    #[Override]
    protected function initialize(): void
    {
        parent::initialize();

        try {
            $this->record = $this->resourceFactory
                ->retrieveFileOrFolderObject($this->identifier);
        } catch (ResourceDoesNotExistException) {
            $this->record = null;
        }
    }

    /**
     * Returns the priority of this provider in the context menu system.
     *
     * This method defines the order in which this provider is evaluated relative
     * to other context menu providers. Providers with higher priority values are
     * evaluated first, which affects the order of items in the resulting context menu.
     *
     * The value of 50 places this provider at a medium priority level, allowing
     * it to be overridden by core providers but taking precedence over lower-priority
     * custom providers.
     *
     * @return int The priority value (50) for this provider
     */
    #[Override]
    public function getPriority(): int
    {
        return 50;
    }

    /**
     * Determines whether a specific context menu item should be rendered.
     *
     * This method checks various conditions to decide if a particular item
     * should appear in the context menu:
     * - Dividers and submenus are always rendered
     * - Items in the disabledItems list are never rendered
     * - The 'algolia_enqueue_one' item is only rendered if the file can be enqueued
     *   for indexing (as determined by the canBeEnqueued method)
     *
     * @param string $itemName The identifier of the menu item to check
     * @param string $type     The type of the menu item (e.g., 'divider', 'submenu', 'item')
     *
     * @return bool True if the item should be rendered, false otherwise
     */
    #[Override]
    protected function canRender(string $itemName, string $type): bool
    {
        if (in_array($type, ['divider', 'submenu'], true)) {
            return true;
        }

        if (in_array($itemName, $this->disabledItems, true)) {
            return false;
        }

        if ($itemName === 'algolia_enqueue_one') {
            return $this->canBeEnqueued();
        }

        return false;
    }

    /**
     * Checks if the current file can be enqueued for indexing.
     *
     * This method performs multiple checks to determine if a file is eligible
     * for indexing in the search engine:
     * - The record must be a File object (not a Folder)
     * - The file must be indexed in TYPO3's file system
     * - The file extension must be in the list of allowed extensions
     * - The user must have permission to edit metadata
     * - The file must have metadata with a UID
     * - The user must have permission to modify the sys_file_metadata table
     * - The user must have access to the default language
     * - The file must be part of a file collection that is configured for indexing
     *
     * Only if all these conditions are met will the "Enqueue for indexing" option
     * be displayed in the context menu.
     *
     * @return bool True if the file can be enqueued for indexing, false otherwise
     */
    private function canBeEnqueued(): bool
    {
        $allowedFileExtensions = $this->typoScriptService->getAllowedFileExtensions();

        $canBeEnqueued = ($this->record instanceof File)
            && ($this->record->isIndexed() === true)
            && $this->isExtensionAllowed($this->record, $allowedFileExtensions)
            && $this->record->checkActionPermission('editMeta')
            && $this->record->getMetaData()->offsetExists('uid')
            && $this->backendUser->check('tables_modify', 'sys_file_metadata')
            && $this->backendUser->checkLanguageAccess(0);

        if ($canBeEnqueued === false) {
            return false;
        }

        // Get all file indexing services
        $indexingServices = $this->indexingServiceRepository
            ->findAllByTableName(FileIndexer::TABLE);

        /** @var IndexingService $indexingService */
        foreach ($indexingServices as $indexingService) {
            $collectionIds = GeneralUtility::intExplode(
                ',',
                $indexingService?->getFileCollections() ?? '',
                true
            );

            $collections = $this
                ->fileCollectionRepository
                ->findAllByCollections($collectionIds);

            foreach ($collections as $collection) {
                if (!($collection instanceof FolderBasedFileCollection)) {
                    continue;
                }

                // Check if the file record identifier starts with the collection's identifier
                if (str_starts_with($this->record->getCombinedIdentifier(), $collection->getItemsCriteria())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks if a file's extension is in the list of allowed extensions.
     *
     * This method determines if a file can be indexed based on its extension.
     * Only files with extensions that are explicitly configured as allowed
     * in the TypoScript configuration will be considered for indexing.
     *
     * @param FileInterface $file           The file to check
     * @param string[]      $fileExtensions Array of allowed file extensions
     *
     * @return bool True if the file extension is allowed, false otherwise
     */
    private function isExtensionAllowed(FileInterface $file, array $fileExtensions): bool
    {
        return in_array($file->getExtension(), $fileExtensions, true);
    }

    /**
     * Provides additional HTML attributes for context menu items.
     *
     * This method adds custom data attributes to the context menu items that
     * are used by the JavaScript handlers to perform the appropriate actions
     * when the item is clicked. For the Algolia indexing items, it adds:
     * - The JavaScript module that handles the action
     * - The backend route URL that the action should call
     *
     * @param string $itemName The identifier of the menu item
     *
     * @return string[] Array of HTML attributes to add to the menu item
     *
     * @throws RouteNotFoundException If the specified route cannot be found
     */
    #[Override]
    protected function getAdditionalAttributes(string $itemName): array
    {
        return [
            'data-callback-module' => '@meine-krankenkasse/typo3-search-algolia/context-menu-actions',
            'data-action-url'      => (string) $this->uriBuilder->buildUriFromRoute('algolia_enqueue_one'),
        ];
    }
}
