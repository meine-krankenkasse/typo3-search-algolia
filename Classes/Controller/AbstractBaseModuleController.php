<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * AbstractBaseModuleController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractBaseModuleController extends ActionController
{
    /**
     * @var ModuleTemplateFactory
     */
    private ModuleTemplateFactory $moduleTemplateFactory;

    /**
     * @var ModuleTemplate|null
     */
    protected ?ModuleTemplate $moduleTemplate = null;

    /**
     * The selected page ID.
     *
     * @var int
     */
    protected int $pageUid = 0;

    /**
     * Constructor.
     *
     * @param ModuleTemplateFactory $moduleTemplateFactory
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
    }

    /**
     * Initializes the controller before invoking an action method.
     */
    protected function initializeAction(): void
    {
        $this->pageUid        = $this->getPageId();
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
     * @return ModuleTemplate
     */
    private function getModuleTemplate(): ModuleTemplate
    {
        $this->updateRoutePackageName();

        $moduleTemplate   = $this->moduleTemplateFactory->create($this->request);
        $permissionClause = $this->getBackendUserAuthentication()->getPagePermsClause(Permission::PAGE_SHOW);
        $pageRecord       = BackendUtility::readPageAccess($this->pageUid, $permissionClause);

        if ($pageRecord !== false) {
            $moduleTemplate
                ->getDocHeaderComponent()
                ->setMetaInformation($pageRecord);
        }

        $additionalQueryParams = [
            'id' => $this->pageUid,
        ];

        $moduleTemplate->makeDocHeaderModuleMenu($additionalQueryParams);
        $moduleTemplate->setModuleId('typo3-module-typo3-search');
        $moduleTemplate->setModuleClass('typo3-module-typo3-search');

        return $moduleTemplate;
    }

    /**
     * Updates the package name of the current route to provide the correct templates
     * for third party extensions.
     *
     * @return void
     *
     * @see \TYPO3\CMS\Backend\View\BackendViewFactory::create
     */
    private function updateRoutePackageName(): void
    {
        $route = $this->request->getAttribute('route');

        if ($route instanceof Route) {
            $route->setOption(
                'packageName',
                'meine-krankenkasse/typo3-search-algolia'
            );
        }
    }

    /**
     * @return BackendUserAuthentication
     */
    private function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
