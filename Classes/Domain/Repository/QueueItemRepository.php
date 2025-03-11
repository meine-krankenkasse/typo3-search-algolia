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
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder   = $connectionPool->getQueryBuilderForTable('tx_typo3searchalgolia_domain_model_queueitem');

        return $queryBuilder
            ->select('indexer_type')
            ->addSelectLiteral('COUNT(*) AS count')
            ->from('tx_typo3searchalgolia_domain_model_queueitem')
            ->groupBy('indexer_type')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
