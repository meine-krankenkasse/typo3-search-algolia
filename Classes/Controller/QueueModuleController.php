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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Dto\QueueDemand;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * QueueModuleController.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueModuleController extends AbstractBaseModuleController
{
    /**
     * @var IndexerFactory
     */
    private readonly IndexerFactory $indexerFactory;

    /**
     * @var IndexingServiceRepository
     */
    private readonly IndexingServiceRepository $indexingServiceRepository;

    /**
     * @var QueueItemRepository
     */
    private readonly QueueItemRepository $queueItemRepository;

    /**
     * @var QueueStatusService
     */
    private readonly QueueStatusService $queueStatusService;

    /**
     * Constructor.
     *
     * @param ModuleTemplateFactory     $moduleTemplateFactory
     * @param IconFactory               $iconFactory
     * @param IndexerFactory            $indexerFactory
     * @param IndexingServiceRepository $indexingServiceRepository
     * @param QueueItemRepository       $queueItemRepository
     * @param QueueStatusService        $queueStatusService
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        IndexerFactory $indexerFactory,
        IndexingServiceRepository $indexingServiceRepository,
        QueueItemRepository $queueItemRepository,
        QueueStatusService $queueStatusService,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $iconFactory
        );

        $this->indexerFactory            = $indexerFactory;
        $this->indexingServiceRepository = $indexingServiceRepository;
        $this->queueItemRepository       = $queueItemRepository;
        $this->queueStatusService        = $queueStatusService;
    }

    /**
     * @return UriBuilder
     */
    private function getBackendUriBuilder(): UriBuilder
    {
        return GeneralUtility::makeInstance(UriBuilder::class);
    }

    /**
     * Adds the new record button to the document header.
     *
     * @return void
     */
    private function addDocHeaderNewButton(): void
    {
        $buttonBar = $this->moduleTemplate
            ->getDocHeaderComponent()
            ->getButtonBar();

        $newButton = $buttonBar->makeLinkButton()
            ->setTitle($this->translate('index_queue.docheader.button.new'))
            ->setShowLabelText(true)
            ->setIcon(
                $this->iconFactory->getIcon(
                    'actions-plus',
                    Icon::SIZE_SMALL
                )
            )
            ->setHref($this->getCreateNewRecordUrl());

        $buttonBar->addButton($newButton);
    }

    /**
     * Returns the URL to create a new indexing service record.
     *
     * @return string
     */
    private function getCreateNewRecordUrl(): string
    {
        return (string) $this->getBackendUriBuilder()
            ->buildUriFromRoute(
                'record_edit',
                [
                    'edit' => [
                        'tx_typo3searchalgolia_domain_model_indexingservice' => [
                            $this->pageUid => 'new',
                        ],
                    ],
                    'returnUrl' => $this->request->getAttribute('normalizedParams')?->getRequestUri(),
                ]
            );
    }

    /**
     * The default action to call.
     *
     * @param QueueDemand|null $queueDemand
     *
     * @return ResponseInterface
     */
    public function indexAction(?QueueDemand $queueDemand = null): ResponseInterface
    {
        if (!$this->checkDatabaseAvailability()) {
            return $this->forwardErrorFlashMessage('error.databaseAvailability');
        }

        $this->addDocHeaderNewButton();

        if (!($queueDemand instanceof QueueDemand)) {
            $queueDemand = GeneralUtility::makeInstance(QueueDemand::class);
        }

        // TODO Use PropertyMapper to map selected indexing services directly into the matching IndexingService-Model
        $indexingServicesUIDs = array_map(
            '\intval',
            $queueDemand->getIndexingServices()
        );

        if ($indexingServicesUIDs !== []) {
            $indexingServices = $this->indexingServiceRepository
                ->findAllByUIDs($indexingServicesUIDs);

            $itemCount = 0;

            /** @var IndexingService $indexingService */
            foreach ($indexingServices as $indexingService) {
                $indexerInstance = $this->indexerFactory
                    ->makeInstanceByType($indexingService->getType());

                if (!($indexerInstance instanceof IndexerInterface)) {
                    continue;
                }

                try {
                    $itemCount += $indexerInstance
                        ->withIndexingService($indexingService)
                        ->dequeueAll()
                        ->enqueueAll();
                } catch (Exception $exception) {
                    $this->addFlashMessage(
                        $exception->getMessage(),
                        $this->translate('flash_message.error.title'),
                        ContextualFeedbackSeverity::ERROR
                    );
                }
            }

            $this->addFlashMessage(
                $this->translate(
                    'index_queue.flash_message.body',
                    [
                        $itemCount,
                    ]
                ),
                $this->translate('index_queue.flash_message.title')
            );
        }

        $this->moduleTemplate->assign(
            'indexingServices',
            $this->indexingServiceRepository->findAll()
        );

        $this->moduleTemplate->assign(
            'queueStatistics',
            $this->queueItemRepository->getStatistics()
        );

        $this->moduleTemplate->assign(
            'lastExecutionTime',
            $this->queueStatusService->getLastExecutionTime()
        );

        return $this->moduleTemplate->renderResponse();
    }
}
