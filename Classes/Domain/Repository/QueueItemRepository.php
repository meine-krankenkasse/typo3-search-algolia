<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository;

use Doctrine\DBAL\Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\QueueItem;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;
use function count;

/**
 * The domain model queue item repository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @extends Repository<QueueItem>
 */
class QueueItemRepository extends Repository
{
    private const string TABLE_NAME = 'tx_typo3searchalgolia_domain_model_queueitem';

    /**
     * Constructor.
     *
     * @param ConnectionPool $connectionPool
     */
    public function __construct(
        ConnectionPool $connectionPool
    ) {
        parent::__construct();

        $this->connectionPool = $connectionPool;
    }

    /**
     * Initializes the repository.
     *
     * @return void
     */
    public function initializeObject(): void
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings
            ->setIgnoreEnableFields(false)
            ->setRespectStoragePage(false);

        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Returns some statistics about the queue item table.
     *
     * @return array<int, array<string, int|string>>
     *
     * @throws Exception
     */
    public function getStatistics(): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE_NAME);

        return $queryBuilder
            ->select('indexer_type')
            ->addSelectLiteral('COUNT(*) AS count')
            ->from(self::TABLE_NAME)
            ->groupBy('indexer_type')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Adds multiple records to the queue table. Returns the number of enqueued items.
     *
     * @param array<int, array<string, int|string>> $records
     *
     * @return int
     */
    public function bulkInsert(array $records): int
    {
        $itemCount = count($records);

        if ($itemCount <= 0) {
            return 0;
        }

        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE_NAME);

        // Avoid errors caused by too many records by dividing them into blocks.
        $recordsChunks = array_chunk($records, 1000);

        foreach ($recordsChunks as $recordsChunk) {
            $connection
                ->bulkInsert(
                    self::TABLE_NAME,
                    $recordsChunk,
                    array_keys($records[0])
                );
        }

        return $itemCount;
    }

    /**
     * Deletes previously added items from the queue. Removes only the items of
     * the given indexer type.
     *
     * @param string $indexerType
     *
     * @return void
     */
    public function deleteByType(string $indexerType): void
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE_NAME);

        $queryBuilder
            ->delete(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->in(
                    'indexer_type',
                    $queryBuilder->createNamedParameter($indexerType)
                )
            )
            ->executeStatement();
    }
}
