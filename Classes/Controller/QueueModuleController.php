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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Dto\QueueDemand;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

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
     * @param IndexerFactory            $indexerFactory
     * @param IndexingServiceRepository $indexingServiceRepository
     * @param QueueItemRepository       $queueItemRepository
     * @param QueueStatusService        $queueStatusService
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IndexerFactory $indexerFactory,
        IndexingServiceRepository $indexingServiceRepository,
        QueueItemRepository $queueItemRepository,
        QueueStatusService $queueStatusService,
    ) {
        parent::__construct($moduleTemplateFactory);

        $this->indexerFactory            = $indexerFactory;
        $this->indexingServiceRepository = $indexingServiceRepository;
        $this->queueItemRepository       = $queueItemRepository;
        $this->queueStatusService        = $queueStatusService;
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
                    ->createByIndexingService($indexingService);

                if ($indexerInstance instanceof IndexerInterface) {
                    try {
                        $itemCount += $indexerInstance->enqueue($indexingService);
                    } catch (Exception $exception) {
                        $this->addFlashMessage(
                            $exception->getMessage(),
                            LocalizationUtility::translate(
                                'index_queue.flash_message.error.title',
                                Constants::EXTENSION_NAME
                            ) ?? '',
                            ContextualFeedbackSeverity::ERROR
                        );
                    }
                }
            }

            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'index_queue.flash_message.body',
                    Constants::EXTENSION_NAME,
                    [
                        $itemCount,
                    ]
                ) ?? '',
                LocalizationUtility::translate(
                    'index_queue.flash_message.title',
                    Constants::EXTENSION_NAME
                ) ?? ''
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
