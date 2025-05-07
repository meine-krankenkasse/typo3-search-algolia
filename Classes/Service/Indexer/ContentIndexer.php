<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

use Override;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ContentIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ContentIndexer extends AbstractIndexer
{
    public const string TABLE = 'tt_content';

    #[Override]
    public function getTable(): string
    {
        return self::TABLE;
    }

    #[Override]
    protected function getAdditionalQueryConstraints(QueryBuilder $queryBuilder): array
    {
        $constraints = [];

        $contentElementTypes = GeneralUtility::trimExplode(
            ',',
            $this->indexingService?->getContentElementTypes() ?? '',
            true
        );

        if ($contentElementTypes !== []) {
            // Filter by CType
            $constraints[] = $queryBuilder->expr()->in(
                'CType',
                $queryBuilder->quoteArrayBasedValueListToStringList($contentElementTypes)
            );
        }

        return $constraints;
    }
}
