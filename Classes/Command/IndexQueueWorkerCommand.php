<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Command;

use Algolia\AlgoliaSearch\Exceptions\BadRequestException;
use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexingServiceRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerFactory;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusService;
use Override;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Command for processing the search indexing queue.
 *
 * This command is responsible for processing items in the indexing queue and sending
 * them to the search engine for indexing. It can be run from the command line or
 * scheduled as a recurring task in the TYPO3 scheduler.
 *
 * The command:
 * - Retrieves queue items that need to be indexed
 * - Processes them in batches for better performance
 * - Handles errors and exceptions during indexing
 * - Updates the queue status after processing
 * - Provides progress information for the scheduler interface
 *
 * It implements ProgressProviderCommandInterface to report progress to the scheduler
 * and LoggerAwareInterface to log indexing operations and errors.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexQueueWorkerCommand extends Command implements LoggerAwareInterface, ProgressProviderCommandInterface
{
    use LoggerAwareTrait;

    /**
     * Symfony I/O helper for console output formatting.
     *
     * This property provides methods for formatted console output, including
     * tables, progress bars, and styled messages. It's initialized in the
     * execute() method with the input and output interfaces.
     */
    private SymfonyStyle $io;

    /**
     * Initializes the command with required dependencies.
     *
     * This constructor injects all the services and repositories needed for the
     * command to process indexing queue items. It follows TYPO3's dependency
     * injection pattern to ensure the command has access to all required
     * functionality without creating tight coupling.
     *
     * @param PersistenceManagerInterface $persistenceManager        TYPO3 persistence manager for database operations
     * @param Registry                    $registry                  TYPO3 registry for persistent storage
     * @param ConnectionPool              $connectionPool            Database connection pool for direct queries
     * @param QueueItemRepository         $queueItemRepository       Repository for queue item operations
     * @param IndexingServiceRepository   $indexingServiceRepository Repository for indexing service configurations
     * @param QueueStatusService          $queueStatusService        Service for tracking indexing execution status
     */
    public function __construct(
        private PersistenceManagerInterface $persistenceManager,
        private Registry $registry,
        private ConnectionPool $connectionPool,
        private QueueItemRepository $queueItemRepository,
        private IndexingServiceRepository $indexingServiceRepository,
        private QueueStatusService $queueStatusService,
    ) {
        parent::__construct();
    }

    /**
     * Configures the command with its name, description, and options.
     *
     * This method sets up the command configuration including:
     * - A descriptive text explaining the command's purpose
     * - Command-line options that control the command's behavior
     *
     * The 'documentsToIndex' option allows users to specify how many documents
     * should be processed in a single execution, with a default of 100. This
     * helps control resource usage and execution time, especially important
     * for scheduled tasks.
     *
     * @return void
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('A worker indexing the items in the index queue.');

        $this->addOption(
            'documentsToIndex',
            'd',
            InputOption::VALUE_OPTIONAL,
            'The number of documents to index per run',
            100
        );
    }

    /**
     * Executes the indexing process for queue items.
     *
     * This method is the main entry point for the command execution. It:
     * 1. Sets up the Symfony I/O helper for formatted console output
     * 2. Displays the command title for better user experience
     * 3. Retrieves the number of documents to index from command options
     * 4. Calls the indexItems() method to process the specified number of queue items
     * 5. Returns a success status code upon completion
     *
     * The method handles the high-level execution flow while delegating the actual
     * indexing work to the indexItems() method.
     *
     * @param InputInterface  $input  The command input containing options and arguments
     * @param OutputInterface $output The command output for displaying messages and progress
     *
     * @return int Command exit code (0 for success, non-zero for failure)
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getName() ?? '');

        $this->indexItems(
            (int) $input->getOption('documentsToIndex')
        );

        return self::SUCCESS;
    }

    /**
     * Processes and indexes a batch of queue items.
     *
     * This method performs the core indexing functionality of the command:
     * 1. Retrieves a limited number of queue items from the repository
     * 2. Creates a progress bar for visual feedback during processing
     * 3. For each queue item:
     *    - Fetches the corresponding record from the database
     *    - Creates an appropriate indexer instance for the record type
     *    - Retrieves the indexing service configuration
     *    - Indexes the record using the indexer and service configuration
     *    - Removes the processed item from the queue
     *    - Updates the progress bar and registry
     * 4. Updates the last execution time in the queue status service
     * 5. Displays a success message upon completion
     *
     * The method handles error cases gracefully, particularly for records that
     * exceed size limits in the search engine.
     *
     * @param int $documentsToIndex The maximum number of documents to process in this batch
     *
     * @return void
     */
    private function indexItems(int $documentsToIndex): void
    {
        $this->io->text('Start indexing');
        $this->io->newLine();

        $queueItems = $this->queueItemRepository
            ->findAllLimited($documentsToIndex);

        $progressBar = $this->io->createProgressBar($queueItems->count());
        $progressBar->start();

        /** @var IndexerFactory $indexerFactory */
        $indexerFactory = GeneralUtility::makeInstance(IndexerFactory::class);

        /** @var QueueItem $item */
        foreach ($queueItems as $item) {
            // Get the underlying record
            $record = $this->fetchRecord($item);

            if ($record === false) {
                continue;
            }

            // Find matching indexer
            $indexerInstance = $indexerFactory->makeInstanceByType($item->getTableName());

            $indexingService = $this->indexingServiceRepository
                ->findByUid($item->getServiceUid());

            try {
                // Perform indexing using each separate indexing service
                if ($indexingService instanceof IndexingService) {
                    $indexerInstance?->indexRecord(
                        $indexingService,
                        $record
                    );
                }

                // Remove index item from queue
                $this->queueItemRepository->remove($item);
                $this->persistenceManager->persistAll();
            } catch (BadRequestException $exception) {
                // TODO Track indexing errors and display failed records in backend

                // Ignore errors of type "Record is too big"
                if (!str_contains($exception->getMessage(), 'Record is too big')) {
                    throw $exception;
                }
            }

            $progressBar->advance();

            // Track progress in the registry
            $this->registry->set(
                Constants::EXTENSION_NAME,
                'index-queue-worker-progress',
                $progressBar->getProgressPercent()
            );
        }

        $this->queueStatusService
            ->setLastExecutionTime(time());

        // @extensionScannerIgnoreLine
        $progressBar->finish();
        $this->io->newLine(2);

        $this->io->success('Indexing done');
    }

    /**
     * Retrieves the database record corresponding to a queue item.
     *
     * This method performs a database query to fetch the complete record that
     * is referenced by a queue item. It:
     * 1. Gets a query builder for the appropriate database table
     * 2. Builds a query to select all fields from the record with the specified UID
     * 3. Executes the query and returns the record as an associative array
     * 4. Catches any exceptions that might occur during the database operation
     *
     * The method is designed to be fault-tolerant, returning false if any errors
     * occur during the database operation rather than allowing exceptions to
     * propagate and potentially disrupt the entire indexing process.
     *
     * @param QueueItem $item The queue item containing table name and record UID information
     *
     * @return array<string, mixed>|false The record as an associative array or false if not found or on error
     */
    private function fetchRecord(QueueItem $item): array|bool
    {
        try {
            $queryBuilder = $this->connectionPool
                ->getQueryBuilderForTable($item->getTableName());

            return $queryBuilder
                ->select('*')
                ->from($item->getTableName())
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $item->getRecordUid()
                    )
                )
                ->executeQuery()
                ->fetchAssociative();
        } catch (Throwable) {
            // Intentionally catching all exceptions: TYPO3's QueryBuilder methods declare
            // @throws annotations for Doctrine exceptions, but the actual exceptions may vary.
            // A missing or inaccessible record should not abort the entire indexing run.
            return false;
        }
    }

    /**
     * Returns the current progress percentage of the indexing process.
     *
     * This method implements the ProgressProviderCommandInterface by retrieving
     * the current progress from the TYPO3 registry. The progress is stored in the
     * registry during the indexing process by the indexItems() method, allowing
     * the scheduler to display accurate progress information.
     *
     * The progress value is stored in the registry as a decimal between 0 and 1,
     * but is returned as a percentage between 0 and 100 to conform to the
     * interface requirements. If no progress value is found in the registry
     * (e.g., if the command hasn't started yet), it returns 0.
     *
     * @return float The progress percentage as a value between 0 and 100
     */
    #[Override]
    public function getProgress(): float
    {
        /** @var int|null $progress */
        $progress = $this->registry->get(
            Constants::EXTENSION_NAME,
            'index-queue-worker-progress',
        );

        return $progress !== null ? $progress * 100.0 : 0;
    }
}
