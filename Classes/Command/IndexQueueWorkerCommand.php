<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Command;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Indexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexerRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\QueueStatusService;
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
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Class IndexQueueWorkerCommand.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexQueueWorkerCommand extends Command implements LoggerAwareInterface, ProgressProviderCommandInterface
{
    use LoggerAwareTrait;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var PersistenceManagerInterface
     */
    private PersistenceManagerInterface $persistenceManager;

    /**
     * @var Registry
     */
    private Registry $registry;

    /**
     * @var ConnectionPool
     */
    private ConnectionPool $connectionPool;

    /**
     * @var QueueItemRepository
     */
    private QueueItemRepository $queueItemRepository;

    /**
     * @var IndexerRepository
     */
    private IndexerRepository $indexerRepository;

    /**
     * @var QueueStatusService
     */
    private QueueStatusService $queueStatusService;

    /**
     * Constructor.
     *
     * @param PersistenceManagerInterface $persistenceManager
     * @param Registry                    $registry
     * @param ConnectionPool              $connectionPool
     * @param QueueItemRepository         $queueItemRepository
     * @param IndexerRepository           $indexerRepository
     * @param QueueStatusService          $queueStatusService
     */
    public function __construct(
        PersistenceManagerInterface $persistenceManager,
        Registry $registry,
        ConnectionPool $connectionPool,
        QueueItemRepository $queueItemRepository,
        IndexerRepository $indexerRepository,
        QueueStatusService $queueStatusService,
    ) {
        parent::__construct();

        $this->persistenceManager  = $persistenceManager;
        $this->registry            = $registry;
        $this->connectionPool      = $connectionPool;
        $this->queueItemRepository = $queueItemRepository;
        $this->indexerRepository   = $indexerRepository;
        $this->queueStatusService  = $queueStatusService;
    }

    /**
     * Configures the command.
     *
     * @return void
     */
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
     * Executes the current command.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
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
     * @param int $documentsToIndex
     *
     * @return void
     */
    private function indexItems(int $documentsToIndex): void
    {
        $this->io->text('Start indexing');
        $this->io->newLine();

        $queueItems = $this->getItemsFromQueue($documentsToIndex);

        $progressBar = $this->io->createProgressBar($queueItems->count());
        $progressBar->start();

        /** @var QueueItem $item */
        foreach ($queueItems as $item) {
            // Get underlying record
            $record = $this->fetchRecord($item);

            if ($record === false) {
                continue;
            }

            // Multiple indexers may exist for each type
            $indexerModels = $this->indexerRepository
                ->findByType($item->getIndexerType());

            /** @var Indexer $indexerModel */
            foreach ($indexerModels as $indexerModel) {
                // Find matching indexer
                $indexerInstance = IndexerRegistry::getIndexerByType($indexerModel->getType());

                $indexerInstance?->indexRecord(
                    $indexerModel,
                    $record
                );
            }

            // Remove index item from queue
            $this->queueItemRepository->remove($item);
            $this->persistenceManager->persistAll();

            $progressBar->advance();

            // Track progress in the registry
            $this->registry->set(
                'tx_typo3searchalgolia',
                'indexQueueWorkerProgress',
                $progressBar->getProgressPercent()
            );
        }

        $this->queueStatusService
            ->setLastExecutionTime(time());

        $progressBar->finish();
        $this->io->newLine(2);

        $this->io->success('Indexing done');
    }

    /**
     * Queries a record from the table the item belongs to.
     *
     * @param QueueItem $item
     *
     * @return array<string, mixed>|false
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
                    $queryBuilder->expr()->in(
                        'uid',
                        $item->getRecordUid()
                    )
                )
                ->executeQuery()
                ->fetchAssociative();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param int $documentsToIndex
     *
     * @return QueryResultInterface<QueueItem>
     */
    private function getItemsFromQueue(int $documentsToIndex): QueryResultInterface
    {
        return $this->queueItemRepository
            ->findAll()
            ->getQuery()
            ->setLimit($documentsToIndex)
            ->setOrderings(
                [
                    'changed' => QueryInterface::ORDER_DESCENDING,
                ]
            )
            ->execute();
    }

    /**
     * @return float
     */
    public function getProgress(): float
    {
        /** @var int|null $progress */
        $progress = $this->registry->get(
            'tx_typo3searchalgolia',
            'indexQueueWorkerProgress',
        );

        return $progress !== null ? $progress * 100.0 : 0;
    }
}
