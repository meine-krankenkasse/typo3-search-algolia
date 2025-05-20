<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use TYPO3\CMS\Core\Registry;

/**
 * This service provides methods for tracking when indexing operations
 * were last executed. It stores and retrieves timestamps in the TYPO3
 * registry to maintain state between indexing runs.
 *
 * The queue status information is used to:
 *
 * - Determine when the last indexing run occurred
 * - Calculate time-based metrics for indexing operations
 * - Support scheduling decisions for future indexing runs
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class QueueStatusService
{
    /**
     * Used to store and retrieve execution timestamps across requests
     * and CLI executions.
     *
     * @var Registry
     */
    private Registry $registry;

    /**
     * Initializes the service with the TYPO3 registry for persistent storage.
     *
     * @param Registry $registry The TYPO3 registry service
     */
    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * This method stores the execution time in the TYPO3 registry under
     * the extension namespace. This timestamp can be used to determine
     * when content was last indexed and to make decisions about when
     * to run indexing operations again.
     *
     * @param int $lastExecutionTime Unix timestamp of when indexing was last executed
     *
     * @return void
     */
    public function setLastExecutionTime(int $lastExecutionTime): void
    {
        $this->registry->set(
            Constants::EXTENSION_NAME,
            'last-exec-time',
            $lastExecutionTime
        );
    }

    /**
     * This method retrieves the stored execution time from the TYPO3 registry.
     * If no timestamp has been stored yet, it returns 0, indicating that
     * indexing has never been run.
     *
     * @return int Unix timestamp of when indexing was last executed, or 0 if never run
     */
    public function getLastExecutionTime(): int
    {
        return $this->registry->get(Constants::EXTENSION_NAME, 'last-exec-time') ?? 0;
    }
}
