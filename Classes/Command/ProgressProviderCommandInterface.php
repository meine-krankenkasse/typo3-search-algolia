<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Command;

/**
 * Interface for commands that can report their progress to a parent scheduler task.
 *
 * This interface defines the contract for commands that need to provide progress
 * information during execution. It's particularly useful for long-running commands
 * that are executed as scheduler tasks, allowing the TYPO3 backend to display
 * progress indicators and status information to administrators.
 *
 * Commands implementing this interface can be used with the ExecuteSchedulableCommandTask
 * to provide visual feedback about their execution progress in the scheduler module.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface ProgressProviderCommandInterface
{
    /**
     * Returns the current progress percentage of the command execution.
     *
     * This method should calculate and return the current progress of the command
     * as a percentage value between 0 and 100. The implementation should determine
     * the progress based on the command's specific execution logic, such as:
     * - Number of items processed out of total items
     * - Time elapsed compared to estimated total time
     * - Completion of distinct phases or steps in the command
     *
     * The returned value is used by the scheduler module to display a progress bar
     * and percentage indicator to administrators monitoring the task execution.
     *
     * @return float The progress percentage as a float value between 0 and 100, with decimal precision (e.g., 44.87)
     */
    public function getProgress(): float;
}
