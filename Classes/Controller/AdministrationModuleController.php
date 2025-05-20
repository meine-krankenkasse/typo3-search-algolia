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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\SearchEngineRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\RateLimitException;
use MeineKrankenkasse\Typo3SearchAlgolia\SearchEngineFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\SearchEngineInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;

/**
 * This controller handles the administration module for the Algolia search extension.
 *
 * The administration module provides a backend interface for managing search engine
 * configurations and performing administrative tasks such as:
 *
 * - Viewing the status of configured search engines and their indices
 * - Clearing search indices to reset the search data
 * - Monitoring index statistics like entry counts and size
 *
 * This controller communicates with the search engine services to retrieve
 * information about indices and to perform administrative operations.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AdministrationModuleController extends AbstractBaseModuleController
{
    /**
     * Factory for creating search engine service instances.
     *
     * This factory is used to create instances of search engine services based on
     * their type (e.g., Algolia). These services provide the actual implementation
     * for communicating with the search engine APIs.
     *
     * @var SearchEngineFactory
     */
    private readonly SearchEngineFactory $searchEngineFactory;

    /**
     * Repository for accessing search engine configuration records.
     *
     * This repository provides access to the search engine configurations stored
     * in the database, including connection details and index names.
     *
     * @var SearchEngineRepository
     */
    private readonly SearchEngineRepository $searchEngineRepository;

    /**
     * Initializes the controller with required dependencies.
     *
     * This constructor injects the necessary services for creating and configuring
     * the backend module interface, as well as the search engine-specific services
     * needed for administrative operations.
     *
     * @param ModuleTemplateFactory  $moduleTemplateFactory  Factory for creating module template instances
     * @param IconFactory            $iconFactory            Factory for creating icon instances
     * @param SearchEngineFactory    $searchEngineFactory    Factory for creating search engine service instances
     * @param SearchEngineRepository $searchEngineRepository Repository for accessing search engine configurations
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        SearchEngineFactory $searchEngineFactory,
        SearchEngineRepository $searchEngineRepository,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $iconFactory
        );

        $this->searchEngineFactory    = $searchEngineFactory;
        $this->searchEngineRepository = $searchEngineRepository;
    }

    /**
     * Displays the main administration interface with search engine information.
     *
     * This action serves as the entry point for the administration module and:
     * 1. Verifies that the required database tables are available
     * 2. Retrieves all configured search engines from the database
     * 3. Groups them by search engine type (e.g., Algolia)
     * 4. Queries each search engine for information about its indices
     * 5. Assigns the collected information to the view for rendering
     *
     * If any errors occur during this process, appropriate flash messages are
     * displayed to inform the administrator.
     *
     * @return ResponseInterface The rendered administration module interface
     */
    public function indexAction(): ResponseInterface
    {
        if (!$this->checkDatabaseAvailability()) {
            return $this->forwardErrorFlashMessage('error.databaseAvailability');
        }

        $searchEngines = $this->searchEngineRepository
            ->findAll();

        $searchEnginesGrouped = [];

        foreach ($searchEngines as $searchEngine) {
            $searchEnginesGrouped[$searchEngine->getEngine()][] = $searchEngine;
        }

        try {
            $searchEnginesInfo = $this->querySearchEngineInformation($searchEnginesGrouped);
        } catch (Exception $exception) {
            return $this->forwardExceptionFlashMessage($exception);
        }

        $this->moduleTemplate->assign(
            'searchEnginesInfo',
            $searchEnginesInfo
        );

        $this->moduleTemplate->assign(
            'moduleUrl',
            $this->getBackendUriBuilder()
                ->buildUriFromRoute('mkk_typo3_search_administration')
        );

        return $this->moduleTemplate->renderResponse();
    }

    /**
     * Clears the content of a specified search index.
     *
     * This action handles the process of emptying a search index:
     * 1. Verifies that the required database tables are available
     * 2. Extracts the index identifier and search engine subtype from the request
     * 3. Creates the appropriate search engine service instance
     * 4. Calls the indexClear method on the service to empty the index
     * 5. Handles any rate limit exceptions that might occur during the operation
     * 6. Adds a success or error flash message based on the operation result
     * 7. Forwards back to the index action to display the updated status
     *
     * This functionality allows administrators to reset search indices when needed,
     * for example before a full reindexing operation.
     *
     * @return ResponseInterface A response that forwards back to the index action
     */
    public function clearIndexAction(): ResponseInterface
    {
        if (!$this->checkDatabaseAvailability()) {
            return $this->forwardToIndexAction();
        }

        $identifier = $this->request->getQueryParams()['identifier'] ?? null;
        $subtype    = $this->request->getQueryParams()['subtype'] ?? null;

        if (($identifier === null) || ($subtype === null)) {
            return $this->forwardToIndexAction();
        }

        $searchEngineService = $this->searchEngineFactory
            ->makeInstanceByServiceSubtype($subtype);

        if (!($searchEngineService instanceof SearchEngineInterface)) {
            return $this->forwardToIndexAction();
        }

        $result = false;

        try {
            $result = $searchEngineService->indexClear($identifier);
        } catch (RateLimitException $exception) {
            $this->addFlashMessage(
                $exception->getMessage(),
                $this->translate('flash_message.error.title'),
                ContextualFeedbackSeverity::ERROR
            );
        }

        if ($result) {
            $this->addFlashMessage(
                $this->translate(
                    'flash_message.success.message.clearIndex',
                    [
                        $identifier,
                    ]
                ),
                $this->translate('flash_message.success.title')
            );
        }

        return $this->forwardToIndexAction();
    }

    /**
     * Creates a forward response to redirect to the index action.
     *
     * This helper method creates a ForwardResponse that redirects the request
     * to the index action of this controller. It's used as a convenient way to
     * return to the main administration view after performing operations or
     * when error conditions are encountered.
     *
     * @return ResponseInterface A forward response that redirects to the index action
     */
    private function forwardToIndexAction(): ResponseInterface
    {
        return new ForwardResponse('index');
    }

    /**
     * Retrieves detailed information about search indices from the search engine services.
     *
     * This method:
     * 1. Iterates through the grouped search engine configurations
     * 2. Creates the appropriate search engine service for each type
     * 3. Queries the service for a list of all indices
     * 4. Matches the configured indices with the actual indices in the search engine
     * 5. Collects information like entry counts, size, and status for each index
     *
     * The collected information is structured by search engine type and configuration UID
     * for easy access in the view template.
     *
     * @param array<string, array<int, SearchEngine>> $searchEnginesGrouped Search engine configurations grouped by type
     *
     * @return array<string, array<int, array<string, int|string|bool>>> Structured information about each index
     */
    private function querySearchEngineInformation(array $searchEnginesGrouped): array
    {
        $searchEnginesInfo = [];

        foreach ($searchEnginesGrouped as $searchEngineSubtype => $searchEngines) {
            $searchEngineService = $this->searchEngineFactory
                ->makeInstanceByServiceSubtype($searchEngineSubtype);

            $indicesList = ($searchEngineService instanceof SearchEngineInterface)
                ? $searchEngineService->indexList()
                : [];

            /** @var SearchEngine $searchEngine */
            foreach ($searchEngines as $searchEngine) {
                /** @var array<string, int|string|bool> $listItem */
                foreach (($indicesList['items'] ?? []) as $listItem) {
                    if ($listItem['name'] === $searchEngine->getIndexName()) {
                        $searchEnginesInfo[$searchEngineSubtype][(int) $searchEngine->getUid()] = $listItem;
                        break;
                    }
                }
            }
        }

        return $searchEnginesInfo;
    }

    /**
     * Creates and returns an instance of the TYPO3 backend URI builder.
     *
     * This helper method provides access to the URI builder service, which is used
     * to generate URLs for backend routes. It's particularly useful for creating
     * action links in the administration module interface, such as links to the
     * clear index action.
     *
     * @return UriBuilder The TYPO3 backend URI builder instance
     */
    protected function getBackendUriBuilder(): UriBuilder
    {
        return GeneralUtility::makeInstance(UriBuilder::class);
    }
}
