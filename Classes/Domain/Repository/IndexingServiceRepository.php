<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for accessing indexing service configurations.
 *
 * This repository provides methods for retrieving indexing service configurations
 * from the database. It offers specialized finder methods for:
 * - Finding indexing services by their unique identifiers
 * - Finding indexing services by the table name they are configured to index
 *
 * The repository is configured to ignore storage page restrictions and to include
 * hidden records in certain queries, ensuring that all relevant indexing services
 * are available to the indexing system regardless of their visibility in the backend.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @extends Repository<IndexingService>
 */
class IndexingServiceRepository extends Repository
{
    /**
     * Initializes the repository with custom query settings.
     *
     * This method configures the repository to ignore enable fields (hidden, starttime,
     * endtime, etc.) and storage page restrictions. This ensures that all indexing
     * service records are accessible to the indexing system, regardless of their
     * visibility settings in the TYPO3 backend or their storage location.
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
     * Finds all indexing service records matching the given list of UIDs.
     *
     * This method retrieves indexing service configurations based on their unique
     * identifiers. It creates a custom query that:
     * - Respects enable fields (only returns visible records)
     * - Ignores storage page restrictions (finds records regardless of their location)
     * - Filters records to match the provided list of UIDs
     *
     * This is typically used when specific indexing services need to be retrieved
     * for processing, such as when refreshing the queue for selected services.
     *
     * @param int[] $indexerUIDs Array of indexing service UIDs to retrieve
     *
     * @return QueryResultInterface<IndexingService> Collection of matching indexing service objects
     *
     * @throws InvalidQueryException If the query cannot be executed properly
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
     * Finds all indexing service records configured for a specific table.
     *
     * This method retrieves all indexing service configurations that are set up
     * to index records from the specified database table. It creates a custom query that:
     * - Respects enable fields (only returns visible records)
     * - Ignores storage page restrictions (finds records regardless of their location)
     * - Filters records to match the provided table name in the 'type' field
     *
     * This is typically used when processing records of a specific type, such as
     * when a page or content element is updated and all relevant indexing services
     * need to be notified to update their indices.
     *
     * @param string $tableName The database table name to find indexing services for
     *
     * @return QueryResultInterface<IndexingService> Collection of matching indexing service objects
     */
    public function findAllByTableName(string $tableName): QueryResultInterface
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
                $tableName
            )
        );

        return $query->execute();
    }
}
