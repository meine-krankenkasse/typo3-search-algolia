<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Command;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\DeletionDetectionService;
use Override;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Command for detecting and queuing records for deletion from the search index.
 *
 * This command identifies records that were previously indexed but should no longer
 * be included in the search index based on current inclusion criteria. It uses the
 * DeletionDetectionService to find these records and then uses the RecordHandler
 * to queue them for deletion from both the indexing queue and the Algolia index.
 *
 * The command:
 * - Scans all indexing services to identify excluded records
 * - Processes records in batches for better performance
 * - Provides verbose output showing what records are being deleted
 * - Handles errors gracefully and provides feedback on the deletion process
 *
 * Use cases:
 * - Records marked with no_search flag after being indexed
 * - Pages moved outside of configured indexing page trees
 * - Content types that no longer match configured CType filters
 * - Document types that no longer match configured doktype filters
 * - Files that have been excluded from indexing
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexDeletionQueueCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Symfony I/O helper for console output formatting.
     *
     * This property provides methods for formatted console output, including
     * tables, progress bars, and styled messages. It's initialized in the
     * execute() method with the input and output interfaces.
     *
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * Service for detecting records that should be removed from the search index.
     *
     * This service compares the current state of records in the database against
     * the indexing criteria to identify records that should be excluded from search.
     *
     * @var DeletionDetectionService
     */
    private readonly DeletionDetectionService $deletionDetectionService;

    /**
     * Handler for database record operations in the search indexing system.
     *
     * This handler provides methods for removing records from both the indexing
     * queue and the search engine index, ensuring consistency between systems.
     *
     * @var RecordHandler
     */
    private readonly RecordHandler $recordHandler;

    /**
     * Initializes the command with required dependencies.
     *
     * @param DeletionDetectionService $deletionDetectionService Service for detecting records to delete
     * @param RecordHandler            $recordHandler            Handler for database record operations
     */
    public function __construct(
        DeletionDetectionService $deletionDetectionService,
        RecordHandler $recordHandler,
    ) {
        parent::__construct();

        $this->deletionDetectionService = $deletionDetectionService;
        $this->recordHandler            = $recordHandler;
    }

    /**
     * Configures the command with its name, description, and options.
     *
     * This method sets up the command configuration including:
     * - A descriptive text explaining the command's purpose
     * - Command-line options that control the command's behavior
     *
     * The 'dry-run' option allows users to see what would be deleted without
     * actually performing the deletion operations.
     *
     * @return void
     */
    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('Detects and queues records for deletion from the search index that no longer meet inclusion criteria.');

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be deleted without actually deleting anything'
        );
    }

    /**
     * Executes the deletion detection and queuing process.
     *
     * This method is the main entry point for the command execution. It:
     * 1. Sets up the Symfony I/O helper for formatted console output
     * 2. Displays the command title for better user experience
     * 3. Detects records that should be deleted from the index
     * 4. Queues the identified records for deletion (unless in dry-run mode)
     * 5. Returns a success status code upon completion
     *
     * The method handles both dry-run mode (showing what would be deleted) and
     * actual deletion mode (performing the deletion operations).
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

        $isDryRun = (bool) $input->getOption('dry-run');

        if ($isDryRun) {
            $this->io->note('Running in dry-run mode - no records will be deleted');
        }

        $this->io->text('Detecting records that should be removed from search index...');
        $this->io->newLine();

        $recordsToDelete = $this->deletionDetectionService->detectRecordsForDeletion();

        if ($recordsToDelete === []) {
            $this->io->success('No records found that need to be removed from the search index.');

            return self::SUCCESS;
        }

        $this->io->text(sprintf('Found %d records that should be removed from the search index:', count($recordsToDelete)));
        $this->io->newLine();

        // Group records by table and indexing service for better display
        $groupedRecords = [];
        foreach ($recordsToDelete as $record) {
            $serviceUid = $record['indexing_service']->getUid();
            $tableName  = $record['table_name'];
            $key        = sprintf('Service %s - %s', $serviceUid, $tableName);

            if (!isset($groupedRecords[$key])) {
                $groupedRecords[$key] = [];
            }

            $groupedRecords[$key][] = $record['record_uid'];
        }

        // Display what will be deleted
        foreach ($groupedRecords as $key => $recordUids) {
            $this->io->text(sprintf('  %s: %d records (UIDs: %s)',
                $key,
                count($recordUids),
                implode(', ', array_slice($recordUids, 0, 10)) . (count($recordUids) > 10 ? '...' : '')
            ));
        }

        $this->io->newLine();

        if ($isDryRun) {
            $this->io->success('Dry-run completed. No records were deleted.');

            return self::SUCCESS;
        }

        if (!$this->io->confirm('Do you want to proceed with deleting these records from the search index?', false)) {
            $this->io->text('Operation cancelled.');

            return self::SUCCESS;
        }

        $this->io->text('Processing deletions...');
        $progressBar = $this->io->createProgressBar(count($recordsToDelete));
        $progressBar->start();

        $successCount = 0;
        $errorCount   = 0;

        foreach ($recordsToDelete as $record) {
            try {
                $this->deleteRecord(
                    $record['indexing_service'],
                    $record['table_name'],
                    $record['record_uid']
                );
                ++$successCount;
            } catch (Throwable $exception) {
                ++$errorCount;
                $this->logger?->error(
                    'Failed to delete record from search index',
                    [
                        'table_name'  => $record['table_name'],
                        'record_uid'  => $record['record_uid'],
                        'service_uid' => $record['indexing_service']->getUid(),
                        'error'       => $exception->getMessage(),
                    ]
                );
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);

        if ($errorCount === 0) {
            $this->io->success(sprintf('Successfully queued %d records for deletion from the search index.', $successCount));
        } else {
            $this->io->warning(sprintf(
                'Completed with %d successful deletions and %d errors. Check logs for details.',
                $successCount,
                $errorCount
            ));
        }

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Deletes a single record from both the indexing queue and the search index.
     *
     * This method uses the RecordHandler to remove a record from both the local
     * indexing queue and the remote Algolia search index. It creates the appropriate
     * indexer instance for the record type and then delegates the deletion to the
     * RecordHandler.
     *
     * @param IndexingService $indexingService The indexing service configuration
     * @param string                                                             $tableName       The database table name of the record
     * @param int                                                                $recordUid       The unique identifier of the record
     *
     * @return void
     */
    private function deleteRecord(
        IndexingService $indexingService,
        string $tableName,
        int $recordUid,
    ): void {
        // Create indexer generator for this specific record
        $rootPageId               = $this->recordHandler->getRecordRootPageId($tableName, $recordUid);
        $indexerInstanceGenerator = $this->recordHandler->createIndexerGenerator($rootPageId, $tableName);

        foreach ($indexerInstanceGenerator as $service => $indexerInstance) {
            // Only process records for the matching indexing service
            if ($service->getUid() === $indexingService->getUid()) {
                $this->recordHandler->deleteRecord(
                    $indexingService,
                    $indexerInstance,
                    $tableName,
                    $recordUid,
                    true // Remove from search engine index as well
                );
                break;
            }
        }
    }
}
