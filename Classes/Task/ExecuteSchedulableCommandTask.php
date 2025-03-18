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
 * Class ExecuteSchedulableCommandTask.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ExecuteSchedulableCommandTask extends \TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask implements ProgressProviderInterface
{
    /**
     * Returns the progress of the task.
     *
     * @return float
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
     * @return Command|null
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
     * @return CommandRegistry
     */
    private function getCommandRegistry(): CommandRegistry
    {
        return GeneralUtility::makeInstance(CommandRegistry::class);
    }
}
