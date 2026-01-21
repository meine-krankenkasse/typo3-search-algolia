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
    protected ModuleTemplate $moduleTemplate;

    /**
     * The selected page ID.
     */
    protected int $pageUid = 0;

    /**
     * Constructor.
     *
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param IconFactory           $iconFactory
     */
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected IconFactory $iconFactory,
    ) {
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
     * Checks if all required database tables for the extension are available.
     *
     * This method verifies that the essential database tables needed by the
     * extension are present in the database. It's used to determine if the
     * extension has been properly installed and set up before attempting
     * operations that depend on these tables.
     *
     * The method checks for the existence of:
     * - Indexing service configuration table
     * - Queue item table
     * - Search engine configuration table
     *
     * @return bool TRUE if all required tables exist, FALSE otherwise
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
     * Extracts the page ID from the current request.
     *
     * This method attempts to find the page ID in the following order:
     * 1. From the parsed body of the request (POST parameters)
     * 2. From the query parameters of the request (GET parameters)
     * 3. Defaults to 0 if no page ID is found
     *
     * The page ID is essential for determining the context of backend module
     * operations and for permission checks.
     *
     * @return int The extracted page ID or 0 if none is found
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
     * Creates and configures a module template for the current request.
     *
     * This method builds a fully configured module template that:
     * 1. Has the correct package name for template resolution
     * 2. Includes page metadata if a valid page is selected
     * 3. Contains the flash message queue for displaying notifications
     * 4. Has the proper module identifier and CSS class for styling
     *
     * The module template is the foundation for rendering the backend module
     * interface in a consistent way that matches TYPO3's design guidelines.
     *
     * @return ModuleTemplate The configured module template instance
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
     * Updates the package name of the current route to provide the correct templates.
     *
     * This method ensures that the TYPO3 template resolution system can find
     * the correct Fluid templates for this extension by setting the package name
     * on the current route. Without this, TYPO3 might not be able to locate
     * the extension's template files correctly.
     *
     * The package name is set to the extension's composer name, which allows
     * the template paths to be resolved according to TYPO3's conventions.
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
     * Provides access to the current backend user object.
     *
     * This method returns the global backend user object, which contains
     * information about the currently logged-in administrator, including
     * their permissions, preferences, and session data.
     *
     * The backend user object is used throughout the controller for permission
     * checks and to provide user-specific functionality.
     *
     * @return BackendUserAuthentication The current backend user object
     */
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Translates a language key into the current backend user's language.
     *
     * This utility method provides a convenient way to access translated strings
     * from the extension's language files. It:
     *
     * 1. Looks up the provided key in the extension's language files
     * 2. Substitutes any placeholders with the provided arguments
     * 3. Returns the translated string or an empty string if no translation is found
     *
     * Using this method ensures consistent translation handling throughout the module.
     *
     * @param string                       $key       The language key to translate
     * @param array<int|float|string>|null $arguments Optional arguments for placeholder substitution
     *
     * @return string The translated string or an empty string if no translation exists
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
