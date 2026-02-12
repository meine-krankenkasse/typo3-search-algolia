<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

/**
 * Interface for tracking indexing queue status.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface QueueStatusServiceInterface
{
    /**
     * Stores the last execution time for the indexing process.
     *
     * @param int $lastExecutionTime Unix timestamp of when indexing was last executed
     *
     * @return void
     */
    public function setLastExecutionTime(int $lastExecutionTime): void;

    /**
     * Returns the last execution time for the indexing process.
     *
     * @return int Unix timestamp of when indexing was last executed, or 0 if never run
     */
    public function getLastExecutionTime(): int;
}
