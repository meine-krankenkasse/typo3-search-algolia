<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

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
 * AdministrationModuleController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AdministrationModuleController extends AbstractBaseModuleController
{
    /**
     * @var SearchEngineFactory
     */
    private readonly SearchEngineFactory $searchEngineFactory;

    /**
     * @var SearchEngineRepository
     */
    private readonly SearchEngineRepository $searchEngineRepository;

    /**
     * Constructor.
     *
     * @param ModuleTemplateFactory  $moduleTemplateFactory
     * @param IconFactory            $iconFactory
     * @param SearchEngineFactory    $searchEngineFactory
     * @param SearchEngineRepository $searchEngineRepository
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
     * The default action to call.
     *
     * @return ResponseInterface
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

        $searchEnginesInfo = $this->querySearchEngineInformation($searchEnginesGrouped);

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
     * Clears the selected index content.
     *
     * @return ResponseInterface
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
            ->createBySubtype($subtype);

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
     * Throws a PropagateResponseException to trigger a redirect to the index action of the module.
     *
     * @return ResponseInterface
     */
    private function forwardToIndexAction(): ResponseInterface
    {
        return new ForwardResponse('index');
    }

    /**
     * @param array<string, array<int, SearchEngine>> $searchEnginesGrouped
     *
     * @return array<string, array<int, array<string, int|string|bool>>>
     */
    private function querySearchEngineInformation(array $searchEnginesGrouped): array
    {
        $searchEnginesInfo = [];

        foreach ($searchEnginesGrouped as $searchEngineSubtype => $searchEngines) {
            $searchEngineService = $this->searchEngineFactory
                ->createBySubtype($searchEngineSubtype);

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
     * Returns an instance of the backend uri builder.
     *
     * @return UriBuilder
     */
    protected function getBackendUriBuilder(): UriBuilder
    {
        return GeneralUtility::makeInstance(UriBuilder::class);
    }
}
