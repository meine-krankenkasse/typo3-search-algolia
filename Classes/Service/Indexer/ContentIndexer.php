<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer;

/**
 * Class ContentIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ContentIndexer extends AbstractIndexer
{
    public const string TYPE  = 'tt_content';
    public const string TABLE = 'tt_content';

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
        return [];
    }
}
