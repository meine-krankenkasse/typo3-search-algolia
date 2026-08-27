<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Scheduler;

use MeineKrankenkasse\Typo3SearchAlgolia\Task\ExecuteSchedulableCommandTask;
use TYPO3\CMS\Core\Configuration\Event\BeforeTcaOverridesEvent;

/**
 * Event listener replacing the scheduler task class for the index queue
 * worker command with a progress-aware subclass.
 *
 * TYPO3's own core\Scheduler\EventListener\AddSchedulableCommandsAsNativeTaskTypes
 * listener auto-registers every "schedulable" console command as a scheduler
 * task type, hardcoding TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask
 * as its taskOptions.className. This listener runs after it (see
 * Configuration/Services.yaml) and overrides that one className entry back to
 * this extension's own ExecuteSchedulableCommandTask subclass, which adds
 * ProgressProviderInterface support so the scheduler backend module can show
 * a progress bar for long-running indexing runs.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class BeforeTcaOverridesEventListener
{
    /**
     * The console command identifier as registered in Configuration/Services.yaml.
     */
    private const COMMAND_IDENTIFIER = 'mkk:queue:index:worker';

    public function __invoke(BeforeTcaOverridesEvent $event): void
    {
        $tca = $event->getTca();

        if (!isset($tca['tx_scheduler_task']['types'][self::COMMAND_IDENTIFIER]['taskOptions'])) {
            return;
        }

        $tca['tx_scheduler_task']['types'][self::COMMAND_IDENTIFIER]['taskOptions']['className']
            = ExecuteSchedulableCommandTask::class;

        $event->setTca($tca);
    }
}
