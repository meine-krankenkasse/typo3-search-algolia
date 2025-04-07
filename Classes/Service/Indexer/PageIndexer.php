<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use Override;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class PageIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class PageIndexer extends AbstractIndexer
{
    public const string TABLE = 'pages';

    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }

    /**
     * @return bool
     */
    public function isIncludeContentElements(): bool
    {
        return ($this->indexingService instanceof IndexingService)
            && $this->indexingService->isIncludeContentElements();
    }

    #[Override]
    protected function getAdditionalQueryConstraints(QueryBuilder $queryBuilder): array
    {
        $constraints = [
            // Include only pages which are not explicitly excluded from search
            $queryBuilder->expr()->eq(
                'no_search',
                0
            ),
        ];

        $pageTypes = GeneralUtility::intExplode(
            ',',
            $this->indexingService?->getPagesDoktype() ?? '',
            true
        );

        if ($pageTypes !== []) {
            // Filter by page type
            $constraints[] = $queryBuilder->expr()->in(
                'doktype',
                $pageTypes
            );
        }

        return $constraints;
    }
}
