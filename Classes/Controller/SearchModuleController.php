<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * SearchModuleController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class SearchModuleController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var ModuleTemplateFactory
     */
    private readonly ModuleTemplateFactory $moduleTemplateFactory;

    /**
     * @var ModuleTemplate
     */
    private ModuleTemplate $moduleTemplate;

    /**
     * The selected page ID.
     *
     * @var int
     */
    private int $pageId = 0;

    /**
     * SearchModuleController constructor.
     *
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param SiteFinder            $siteFinder
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        SiteFinder $siteFinder,
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
    }

    /**
     * Initialize action.
     *
     * @return void
     */
    protected function initializeAction(): void
    {
        parent::initializeAction();

        $this->pageId         = $this->getPageId();
        $this->moduleTemplate = $this->getModuleTemplate();
    }

    /**
     * Returns the page ID extracted from the given request object.
     *
     * @return int
     */
    private function getPageId(): int
    {
        return (int) ($this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? -1);
    }

    /**
     * Returns the module template instance.
     *
     * @return ModuleTemplate
     */
    private function getModuleTemplate(): ModuleTemplate
    {
        $pageRecord = BackendUtility::readPageAccess(
            $this->pageId,
            $this->getBackendUserAuthentication()->getPagePermsClause(Permission::PAGE_SHOW)
        );

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setBodyTag('<body class="typo3-module-typo3-search-algolia">');
        $moduleTemplate->setModuleId('typo3-module-typo3-search-algolia');
        $moduleTemplate->setTitle(
            $this->translate(
                'mlang_tabs_tab',
                null,
                'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang_mod_algolia.xlf'
            ),
            $pageRecord['title'] ?? ''
        );

        if ($pageRecord !== false) {
            $moduleTemplate
                ->getDocHeaderComponent()
                ->setMetaInformation($pageRecord);
        }

        return $moduleTemplate;
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Returns the translated language label for the given identifier.
     *
     * @param string                       $key
     * @param array<int|float|string>|null $arguments
     * @param string                       $languageFile
     *
     * @return string
     */
    protected function translate(
        string $key,
        ?array $arguments = null,
        string $languageFile = 'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf',
    ): string {
        return LocalizationUtility::translate(
            $languageFile . ':' . $key,
            null,
            $arguments
        ) ?? LocalizationUtility::translate(
            $languageFile . ':error.missingTranslation',
            null,
            $arguments
        ) . ' ' . $key;
    }

    /**
     * The main entry point.
     *
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface
    {
        return $this->moduleTemplate->renderResponse('Backend/SearchModule');
    }
}
