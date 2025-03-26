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
 * Class QueueStatusService.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class QueueStatusService
{
    /**
     * @var Registry
     */
    private Registry $registry;

    /**
     * Constructor.
     *
     * @param Registry $registry
     */
    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Sets the timestamp at which indexing last ran through the queue.
     *
     * @param int $lastExecutionTime
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
     * Returns the timestamp of the last indexing run.
     *
     * @return int
     */
    public function getLastExecutionTime(): int
    {
        return $this->registry->get(Constants::EXTENSION_NAME, 'last-exec-time') ?? 0;
    }
}
