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
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusServiceInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * This controller handles the indexing queue management module in the TYPO3 backend.
 *
 * The queue module provides a backend interface for managing the indexing queue:
 *
 * - Viewing statistics about items currently in the queue
 * - Adding new items to the queue based on selected indexing services
 * - Removing items from the queue
 * - Creating new indexing service configurations
 *
 * This controller coordinates the interaction between the user interface and
 * the underlying indexing system, allowing administrators to control what
 * content gets indexed and when.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueModuleController extends AbstractBaseModuleController
{
    /**
     * Initializes the controller with required dependencies.
     *
     * This constructor injects the necessary services for creating and configuring
     * the backend module interface, as well as the indexing-specific services
     * needed for queue management operations.
     *
     * @param ModuleTemplateFactory       $moduleTemplateFactory     Factory for creating module template instances
     * @param IconFactory                 $iconFactory               Factory for creating icon instances
     * @param IndexerFactory              $indexerFactory            Factory for creating indexer instances
     * @param IndexingServiceRepository   $indexingServiceRepository Repository for accessing indexing service configurations
     * @param QueueItemRepository         $queueItemRepository       Repository for managing queue items
     * @param QueueStatusServiceInterface $queueStatusService        Service for tracking indexing execution status
     */
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        private readonly IndexerFactory $indexerFactory,
        private readonly IndexingServiceRepository $indexingServiceRepository,
        private readonly QueueItemRepository $queueItemRepository,
        private readonly QueueStatusServiceInterface $queueStatusService,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $iconFactory
        );
    }

    /**
     * Creates and returns an instance of the TYPO3 backend URI builder.
     *
     * This helper method provides access to the URI builder service, which is used
     * to generate URLs for backend routes. It's particularly useful for creating
     * action links in the queue module interface, such as links to create new
     * indexing service records.
     *
     * @return UriBuilder The TYPO3 backend URI builder instance
     */
    private function getBackendUriBuilder(): UriBuilder
    {
        return GeneralUtility::makeInstance(UriBuilder::class);
    }

    /**
     * Adds a "New Indexing Service" button to the document header.
     *
     * This method enhances the module's user interface by adding a button
     * to the document header that allows administrators to create new
     * indexing service configurations directly from the queue module.
     *
     * The button is configured with:
     * - A translated title
     * - A visible label text
     * - A "plus" icon to indicate the creation action
     * - A link to the record creation form
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
     * Generates the URL for creating a new indexing service record.
     *
     * This helper method builds a URL that points to TYPO3's record editing
     * form, pre-configured to create a new indexing service record on the
     * current page. The URL includes:
     *
     * - The target table (indexing service)
     * - The page ID where the record should be created
     * - A return URL to redirect back to the queue module after saving
     *
     * @return string The fully constructed URL for the record creation form
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
     * Displays the main queue management interface and processes queue operations.
     *
     * This action serves as the entry point for the queue module and performs several tasks:
     * 1. Verifies that the required database tables are available
     * 2. Adds the "New Indexing Service" button to the document header
     * 3. Processes deletion requests for queue items if present in the request
     * 4. Handles the queue demand object that controls filtering and selection
     * 5. If indexing services are selected:
     *    - Retrieves the corresponding indexing service configurations
     *    - Creates appropriate indexer instances for each service
     *    - Refreshes the queue by removing and re-adding items
     *    - Displays success or error messages based on the operation results
     * 6. Assigns data to the view for rendering:
     *    - Available indexing services for selection
     *    - Queue statistics (counts by table, etc.)
     *    - Last execution time of the indexing process
     *
     * This comprehensive action handles both the display and processing aspects
     * of the queue management interface.
     *
     * @param QueueDemand|null $queueDemand Filter and selection criteria for queue operations
     *
     * @return ResponseInterface The rendered queue module interface
     */
    public function indexAction(?QueueDemand $queueDemand = null): ResponseInterface
    {
        if (!$this->checkDatabaseAvailability()) {
            return $this->forwardErrorFlashMessage('error.databaseAvailability');
        }

        $this->addDocHeaderNewButton();

        // Remove all entries from queue item table matching the submitted table
        if ($this->request->hasArgument('delete')) {
            $tableName = $this->request->getArgument('delete')['table_name'] ?? null;

            $this->queueItemRepository
                ->deleteByTableAndRecordUIDs(
                    $tableName
                );
        }

        if (!($queueDemand instanceof QueueDemand)) {
            $queueDemand = GeneralUtility::makeInstance(QueueDemand::class);
        }

        $selectedIndexingServices = $queueDemand->getIndexingServices();

        if ($queueDemand->getIndexingService() !== 0) {
            $selectedIndexingServices[] = $queueDemand->getIndexingService();
        }

        // TODO Use PropertyMapper to map selected indexing services directly into the matching IndexingService-Model
        $indexingServicesUIDs = array_map('\intval', $selectedIndexingServices);

        if ($indexingServicesUIDs !== []) {
            $indexingServices = $this->indexingServiceRepository
                ->findAllByUIDs($indexingServicesUIDs);

            if ($indexingServices->count() > 0) {
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
                            ->withExcludeHiddenPages(true)
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
