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
 * Interface for command who can provide their progress to a parent scheduler task.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
interface ProgressProviderCommandInterface
{
    /**
     * Returns the progress of a command.
     *
     * @return float The progress of the command as a two decimal precision float, e.g., 44.87
     */
    public function getProgress(): float;
}
