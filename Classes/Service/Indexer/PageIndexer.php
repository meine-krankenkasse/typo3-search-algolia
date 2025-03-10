<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Class PageIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class PageIndexer extends AbstractIndexer
{
    public const string TYPE  = 'page';
    public const string TABLE = 'pages';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getTable(): string
    {
        return self::TABLE;
    }

    public function getIndexerConstraints(): array
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
