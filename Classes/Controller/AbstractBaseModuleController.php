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
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use Override;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

use function is_array;

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
    private readonly ModuleTemplateFactory $moduleTemplateFactory;

    /**
     * @var IconFactory
     */
    protected IconFactory $iconFactory;

    /**
     * @var ModuleTemplate
     */
    protected ModuleTemplate $moduleTemplate;

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
     * @param IconFactory           $iconFactory
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->iconFactory           = $iconFactory;
    }

    /**
     * Initializes the controller before invoking any action method.
     */
    #[Override]
    protected function initializeAction(): void
    {
        $this->pageUid = $this->getPageId();

        // @extensionScannerIgnoreLine
        $this->moduleTemplate = $this->getModuleTemplate();
    }

    /**
     * The error entry point.
     *
     * @return ResponseInterface
     */
    #[Override]
    protected function errorAction(): ResponseInterface
    {
        return $this->moduleTemplate->renderResponse();
    }

    /**
     * Adds a flash message to the message queue and forward to the error action to abort further processing.
     *
     * @param string                     $key
     * @param ContextualFeedbackSeverity $severity
     *
     * @return ResponseInterface
     */
    protected function forwardErrorFlashMessage(
        string $key,
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::ERROR,
    ): ResponseInterface {
        $this->moduleTemplate->addFlashMessage(
            $this->translate($key),
            $this->translate('error.title'),
            $severity
        );

        return new ForwardResponse('error');
    }

    /**
     * Adds a flash message to the message queue and forward to the error action to abort further processing.
     *
     * @param Exception                  $exception
     * @param ContextualFeedbackSeverity $severity
     *
     * @return ResponseInterface
     */
    protected function forwardExceptionFlashMessage(
        Exception $exception,
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::ERROR,
    ): ResponseInterface {
        $this->moduleTemplate->addFlashMessage(
            $exception->getMessage(),
            $this->translate('error.title'),
            $severity
        );

        return new ForwardResponse('error');
    }

    /**
     * Returns TRUE if the required database tables are available.
     *
     * @return bool
     */
    protected function checkDatabaseAvailability(): bool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_typo3searchalgolia_domain_model_indexingservice')
            ->createSchemaManager()
            ->tablesExist([
                'tx_typo3searchalgolia_domain_model_indexingservice',
                'tx_typo3searchalgolia_domain_model_queueitem',
                'tx_typo3searchalgolia_domain_model_searchengine',
            ]);
    }

    /**
     * Returns the page ID extracted from the given request object.
     *
     * @return int
     */
    private function getPageId(): int
    {
        $parsedBody = $this->request->getParsedBody();

        if (is_array($parsedBody) && isset($parsedBody['id'])) {
            return (int) $parsedBody['id'];
        }

        return (int) ($this->request->getQueryParams()['id'] ?? 0);
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

        $moduleTemplate
            ->setFlashMessageQueue($this->getFlashMessageQueue())
            ->makeDocHeaderModuleMenu($additionalQueryParams)
            ->setModuleId('typo3-module-typo3-search')
            ->setModuleClass('typo3-module-typo3-search');

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
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Returns the translated language label for the given identifier.
     *
     * @param string                       $key
     * @param array<int|float|string>|null $arguments
     *
     * @return string
     */
    protected function translate(string $key, ?array $arguments = null): string
    {
        return LocalizationUtility::translate(
            $key,
            Constants::EXTENSION_NAME,
            $arguments
        ) ?? '';
    }
}
