<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Task;

use Exception;
use MeineKrankenkasse\Typo3SearchAlgolia\Command\ProgressProviderCommandInterface;
use Override;
use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\ProgressProviderInterface;

/**
 * Task for executing schedulable commands with progress tracking.
 *
 * This class extends TYPO3's standard ExecuteSchedulableCommandTask to add
 * progress reporting capabilities. It implements the ProgressProviderInterface
 * to allow the TYPO3 scheduler to display progress information for long-running
 * indexing tasks in the backend interface.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ExecuteSchedulableCommandTask extends \TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask implements ProgressProviderInterface
{
    /**
     * Returns the progress of the task as a percentage.
     *
     * This method implements the ProgressProviderInterface by retrieving
     * the progress from the scheduled command if it implements the
     * ProgressProviderCommandInterface. If the command doesn't support
     * progress reporting, it returns 0.
     *
     * @return float Progress as a percentage between 0 and 100
     */
    #[Override]
    public function getProgress(): float
    {
        $scheduledCommand = $this->getScheduledCommand();

        return ($scheduledCommand instanceof ProgressProviderCommandInterface)
            ? $scheduledCommand->getProgress()
            : 0;
    }

    /**
     * Returns the instance of the scheduled command.
     *
     * This method retrieves the command object that this task is configured
     * to execute. It uses the command identifier stored in this task to
     * look up the corresponding command in the TYPO3 command registry.
     * If the command cannot be found, it returns null.
     *
     * @return Command|null The command instance or null if not found
     */
    private function getScheduledCommand(): ?Command
    {
        try {
            return $this->getCommandRegistry()->getCommandByIdentifier($this->commandIdentifier);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Returns an instance of the TYPO3 command registry.
     *
     * This method creates and returns a CommandRegistry instance that provides
     * access to all registered console commands in the TYPO3 system. It uses
     * TYPO3's GeneralUtility to ensure proper dependency injection.
     *
     * @return CommandRegistry The command registry instance
     */
    private function getCommandRegistry(): CommandRegistry
    {
        return GeneralUtility::makeInstance(CommandRegistry::class);
    }
}
