<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Command;

use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Indexer;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\IndexerRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository\QueueItemRepository;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
     * Constructor.
     *
     * @param Registry            $registry
     * @param ConnectionPool      $connectionPool
     * @param QueueItemRepository $queueItemRepository
     * @param IndexerRepository   $indexerRepository
     */
    public function __construct(
        Registry $registry,
        ConnectionPool $connectionPool,
        QueueItemRepository $queueItemRepository,
        IndexerRepository $indexerRepository,
    ) {
        parent::__construct();

        $this->registry            = $registry;
        $this->connectionPool      = $connectionPool;
        $this->queueItemRepository = $queueItemRepository;
        $this->indexerRepository   = $indexerRepository;
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
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getName() ?? '');

        // Authenticate CommandLineUserAuthentication user for DataHandler usage
        $GLOBALS['BE_USER']->backendCheckLogin();

        $this->indexItems(
            (int) $input->getOption('documentsToIndex')
        );

        return self::SUCCESS;
    }

    /**
     * @param int $documentsToIndex
     *
     * @return void
     *
     * @throws Exception
     */
    private function indexItems(int $documentsToIndex): void
    {
        $this->io->text('Start indexing');
        $this->io->newLine();

        $queueItems = $this->getItemsFromQueue($documentsToIndex);

        $progressBar = $this->io->createProgressBar($documentsToIndex);
        $progressBar->start();

        /** @var QueueItem $item */
        foreach ($queueItems as $item) {
            $queryBuilderTable = $this->connectionPool
                ->getQueryBuilderForTable($item->getTableName());

            // Multiple indexers may exist for each type
            $indexerModels = $this->indexerRepository
                ->findByType($item->getIndexerType());

            // Query record
            $record = $queryBuilderTable
                ->select('*')
                ->from($item->getTableName())
                ->where(
                    $queryBuilderTable->expr()->in(
                        'uid',
                        $item->getRecordUid()
                    )
                )
                ->executeQuery()
                ->fetchAssociative();

            /** @var Indexer $indexerModel */
            foreach ($indexerModels as $indexerModel) {
                // Find matching indexer
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_search_algolia']['indexer'] as $indexerConfiguration) {
                    /** @var IndexerInterface $indexerInstance */
                    $indexerInstance = GeneralUtility::makeInstance($indexerConfiguration['className']);

                    if ($indexerInstance->getType() !== $indexerModel->getType()) {
                        continue;
                    }

                    $indexerInstance->indexRecord($indexerModel, $record);
                }
            }

            $progressBar->advance();

            // Track progress in the registry
            $this->registry->set(
                'indexQueueWorkerProgress',
                'progress',
                $progressBar->getProgressPercent()
            );
        }

        $progressBar->finish();
        $this->io->newLine(2);

        $this->io->success('Indexing done');
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
        $progress = $this->registry->get('indexQueueWorkerProgress', 'progress');

        return $progress !== null ? $progress * 100.0 : 0;
    }
}
