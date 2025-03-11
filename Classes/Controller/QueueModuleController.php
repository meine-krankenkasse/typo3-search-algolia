<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Dto\QueueDemand;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Indexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexerRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

use function count;

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
     * @var IndexerRepository
     */
    private IndexerRepository $indexerRepository;

    /**
     * @var QueueItemRepository
     */
    private QueueItemRepository $queueItemRepository;

    /**
     * @var QueueStatusService
     */
    private QueueStatusService $queueStatusService;

    /**
     * Constructor.
     *
     * @param ModuleTemplateFactory $moduleTemplateFactory
     * @param IndexerRepository     $indexerRepository
     * @param QueueItemRepository   $queueItemRepository
     * @param QueueStatusService    $queueStatusService
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IndexerRepository $indexerRepository,
        QueueItemRepository $queueItemRepository,
        QueueStatusService $queueStatusService,
    ) {
        parent::__construct($moduleTemplateFactory);

        $this->indexerRepository   = $indexerRepository;
        $this->queueItemRepository = $queueItemRepository;
        $this->queueStatusService  = $queueStatusService;
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
        // TODO Use PropertyMapper to map selected indexers directly into the matching Indexer-Model

        if (!($queueDemand instanceof QueueDemand)) {
            /** @var QueueDemand $searchDemand */
            $queueDemand = GeneralUtility::makeInstance(QueueDemand::class);
        }

        $indexerUIDs = array_map(
            '\intval',
            $queueDemand->getIndexers()
        );

        if (count($indexerUIDs) > 0) {
            $indexerModels = $this->indexerRepository
                ->findAllByUIDs($indexerUIDs);

            $itemCount = 0;

            /** @var Indexer $indexerModel */
            foreach ($indexerModels as $indexerModel) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Constants::EXTENSION_NAME]['indexer'] as $indexerConfiguration) {
                    /** @var IndexerInterface $indexerInstance */
                    $indexerInstance = GeneralUtility::makeInstance($indexerConfiguration['className']);

                    if ($indexerInstance->getType() !== $indexerModel->getType()) {
                        continue;
                    }

                    $itemCount += $indexerInstance->enqueue();
                }
            }

            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'index_queue.flash_message.body',
                    Constants::EXTENSION_NAME,
                    [
                        $itemCount,
                    ]
                ),
                LocalizationUtility::translate(
                    'index_queue.flash_message.title',
                    Constants::EXTENSION_NAME
                )
            );
        }

        $this->moduleTemplate->assign(
            'indexers',
            $this->indexerRepository->findAll()
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
