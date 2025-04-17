<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\ContextMenu\ItemProviders;

use Override;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;

/**
 * Provides click menu items for files and folders.
 */
class QueueProvider extends AbstractProvider
{
    /**
     * @var File|Folder|null
     */
    private Folder|File|null $record = null;

    /**
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
     * @return bool
     */
    #[Override]
    public function canHandle(): bool
    {
        return $this->table === 'sys_file';
    }

    /**
     * Initialize file object.
     */
    #[Override]
    protected function initialize(): void
    {
        parent::initialize();

        try {
            $this->record = GeneralUtility::makeInstance(ResourceFactory::class)
                ->retrieveFileOrFolderObject($this->identifier);
        } catch (ResourceDoesNotExistException) {
            $this->record = null;
        }
    }

    /**
     * Returns the provider priority which is used for determining the order in which providers are adding items
     * to the result array. Highest priority means provider is evaluated first.
     *
     * @return int
     */
    #[Override]
    public function getPriority(): int
    {
        return 50;
    }

    /**
     * Checks whether certain item can be rendered (e.g. check for disabled items or permissions).
     *
     * @param string $itemName
     * @param string $type
     *
     * @return bool
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
     * @return bool
     */
    private function canBeEnqueued(): bool
    {
        // TODO Rely on typoscript configured file extensions

        return ($this->record instanceof File)
            && ($this->record->isIndexed() === true)
            && ($this->record->getExtension() === 'pdf')
            && $this->record->checkActionPermission('editMeta')
            && $this->record->getMetaData()->offsetExists('uid')
            && $this->backendUser->check('tables_modify', 'sys_file_metadata')
            && $this->backendUser->checkLanguageAccess(0);
    }

    /**
     * @param string $itemName
     *
     * @return string[]
     *
     * @throws RouteNotFoundException
     */
    #[Override]
    protected function getAdditionalAttributes(string $itemName): array
    {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

        return [
            'data-callback-module' => '@meine-krankenkasse/typo3-search-algolia/context-menu-actions',
            'data-action-url'      => (string) $uriBuilder->buildUriFromRoute('algolia_enqueue_one'),
        ];
    }
}
