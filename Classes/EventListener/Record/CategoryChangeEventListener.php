<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\CategoryRecordRequeuerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;

/**
 * Requeues the records of a system category after the category was saved.
 *
 * It also runs for a newly created category, whose assignments do not exist
 * yet at that point. DataHandlerHook::processDatamap_afterAllOperations()
 * requeues those, and DataHandlerHook::processCmdmap_postProcess() requeues
 * the records of deleted categories.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class CategoryChangeEventListener
{
    /**
     * Constructor.
     *
     * @param CategoryRecordRequeuerInterface $categoryRecordRequeuer The requeuer handling the category's records
     */
    public function __construct(
        private CategoryRecordRequeuerInterface $categoryRecordRequeuer,
    ) {
    }

    /**
     * Requeues the records of a saved category.
     *
     * @param DataHandlerRecordUpdateEvent $event The record update event
     *
     * @return void
     */
    public function __invoke(DataHandlerRecordUpdateEvent $event): void
    {
        if ($event->getTable() === 'sys_category') {
            $this->categoryRecordRequeuer->requeue($event->getRecordUid());
        }
    }
}
