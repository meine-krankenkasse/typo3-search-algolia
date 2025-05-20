<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Domain\Repository;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\SearchEngine;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for accessing search engine configurations.
 *
 * This repository provides methods for retrieving search engine configurations
 * from the database. It is primarily used by:
 * - The administration module to display and manage search engine configurations
 * - The indexing system to determine which search engines should receive indexed content
 *
 * The repository is configured to ignore storage page restrictions, ensuring that
 * all search engine configurations are available to the system regardless of where
 * they are stored in the page tree.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @extends Repository<SearchEngine>
 */
class SearchEngineRepository extends Repository
{
    /**
     * Initializes the repository with custom query settings.
     *
     * This method configures the repository's default query settings to:
     * - Respect enable fields (only return visible records)
     * - Ignore storage page restrictions (find records regardless of their location)
     *
     * These settings ensure that only active search engine configurations are used
     * for indexing operations, but they can be found regardless of which page they
     * are stored on. This is important because search engine configurations are
     * system-wide settings that need to be accessible from any context.
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
}
