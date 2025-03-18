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

/**
 * Class NewsIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class NewsIndexer extends AbstractIndexer
{
    public const string TYPE = 'news';

    public const string TABLE = 'tx_news_domain_model_news';

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
}
