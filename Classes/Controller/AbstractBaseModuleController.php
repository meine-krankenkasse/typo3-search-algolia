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
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * AbstractBaseModuleController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractBaseModuleController
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
     * The default action to call.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->initializeAction($request);

        return $this->htmlResponse();
    }

    /**
     * Initializes the controller before invoking an action method.
     *
     * @param ServerRequestInterface $request
     */
    private function initializeAction(ServerRequestInterface $request): void
    {
        $this->pageUid        = $this->getPageId($request);
        $this->moduleTemplate = $this->getModuleTemplate($request, $this->pageUid);
    }

    /**
     * Returns the page ID extracted from the given request object.
     *
     * @param ServerRequestInterface $request
     *
     * @return int
     */
    private function getPageId(ServerRequestInterface $request): int
    {
        return (int) ($request->getParsedBody()['id'] ?? $request->getQueryParams()['id'] ?? -1);
    }

    /**
     * @param ServerRequestInterface $request
     * @param int                    $pageUid
     *
     * @return ModuleTemplate
     */
    private function getModuleTemplate(ServerRequestInterface $request, int $pageUid): ModuleTemplate
    {
        $this->updateRoutePackageName($request);

        $moduleTemplate   = $this->moduleTemplateFactory->create($request);
        $permissionClause = $this->getBackendUserAuthentication()->getPagePermsClause(Permission::PAGE_SHOW);
        $pageRecord       = BackendUtility::readPageAccess($pageUid, $permissionClause);

        if ($pageRecord !== false) {
            $moduleTemplate
                ->getDocHeaderComponent()
                ->setMetaInformation($pageRecord);
        }

        $additionalQueryParams = [
            'id' => $this->getPageId($request),
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
     * @param ServerRequestInterface $request
     *
     * @return void
     *
     * @see \TYPO3\CMS\Backend\View\BackendViewFactory::create
     */
    private function updateRoutePackageName(ServerRequestInterface $request): void
    {
        $route = $request->getAttribute('route');

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

    /**
     * @return ResponseInterface
     */
    abstract protected function htmlResponse(): ResponseInterface;
}
