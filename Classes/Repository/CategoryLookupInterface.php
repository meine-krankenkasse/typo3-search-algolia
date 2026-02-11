<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Repository;

/**
 * Interface for looking up individual category records by UID.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface CategoryLookupInterface
{
    /**
     * Returns a single system category by its UID.
     *
     * @param int $uid The UID of the category to find
     *
     * @return array<string, int|string|null>|null The category record, or null if not found
     */
    public function findByUid(int $uid): ?array;
}
