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
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
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
    public const string TYPE = 'page';

    public const string TABLE = 'pages';

    #[Override]
    public function getType(): string
    {
        return self::TYPE;
    }

    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }

    #[Override]
    protected function getPages(IndexingService $indexingService): array
    {
        // Get configured page UIDs
        $pagesSingle    = GeneralUtility::intExplode(',', $indexingService->getPagesSingle(), true);
        $pagesRecursive = GeneralUtility::intExplode(',', $indexingService->getPagesRecursive(), true);

        // Recursively determine all associated pages and subpages
        $pageIds   = [[]];
        $pageIds[] = $pagesSingle;
        $pageIds[] = $this->pageRepository->getPageIdsRecursive($pagesRecursive, 9999);

        return array_merge(...$pageIds);
    }

    #[Override]
    protected function getQueryItemsConstraints(): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable(self::TABLE);

        return [
            $queryBuilder->expr()->eq(
                'doktype',
                PageRepository::DOKTYPE_DEFAULT
            ),
            $queryBuilder->expr()->eq(
                'no_search',
                0
            ),
        ];
    }
}
