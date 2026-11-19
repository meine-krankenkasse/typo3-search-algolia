<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\DataHandling;

/**
 * Requeues the records of a system category after the category changed.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface CategoryRecordRequeuerInterface
{
    /**
     * Remembers the records currently assigned to a category and its
     * subcategories, so the next requeue of the category also covers records
     * whose assignment the upcoming save removes.
     *
     * @param int $categoryUid The UID of the category about to be saved
     *
     * @return void
     */
    public function rememberAssignedRecords(int $categoryUid): void;

    /**
     * Drops the records remembered for a category, e.g. once DataHandler
     * refused its save and the category is not requeued.
     *
     * @param int $categoryUid The UID of the category
     *
     * @return void
     */
    public function forgetRememberedRecords(int $categoryUid): void;

    /**
     * Requeues the records assigned to a category and its subcategories,
     * together with the records remembered before the save and the records
     * added by CollectCategoryRecordsEvent listeners.
     *
     * @param int $categoryUid The UID of the changed category
     *
     * @return void
     */
    public function requeue(int $categoryUid): void;
}
