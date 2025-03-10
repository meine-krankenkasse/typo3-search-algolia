<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\Indexer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * The domain model indexer repository.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @extends Repository<Indexer>
 */
class IndexerRepository extends Repository
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
            ->setIgnoreEnableFields(true)
            ->setRespectStoragePage(false);

        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Finds all indexer records matching the given list of indexer UIDs.
     *
     * @param int[] $indexerUIDs
     *
     * @return QueryResultInterface<Indexer>
     *
     * @throws InvalidQueryException
     */
    public function findAllByUIDs(array $indexerUIDs): QueryResultInterface
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings
            ->setIgnoreEnableFields(false)
            ->setRespectStoragePage(false);

        $query = $this->createQuery();
        $query->setQuerySettings($querySettings);
        $query->matching(
            $query->in(
                'uid',
                $indexerUIDs
            )
        );

        return $query->execute();
    }

    /**
     * Finds all indexer records matching the given type.
     *
     * @param string $type
     *
     * @return QueryResultInterface<Indexer>
     */
    public function findByType(string $type): QueryResultInterface
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings
            ->setIgnoreEnableFields(false)
            ->setRespectStoragePage(false);

        $query = $this->createQuery();
        $query->setQuerySettings($querySettings);
        $query->matching(
            $query->equals(
                'type',
                $type
            )
        );

        return $query->execute();
    }
}
