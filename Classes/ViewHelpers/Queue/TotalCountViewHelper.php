<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\ViewHelpers\Queue;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns the total number of enqueued items.
 *
 * Example
 * =======
 *
 *    Inline:
 *
 *      {mkk:queue.totalCount(statistics: 'queueStatistics')}
 *
 *    Tag-based:
 *
 *      <mkk:queue.totalCount statistics="queueStatistics" />
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class TotalCountViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeChildren = false;

    /**
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Initialize all arguments.
     *
     * @return void
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'statistics',
            'array',
            'The queue item statistics array'
        );
    }

    /**
     * @return int
     */
    public function render(): int
    {
        $statistics = $this->arguments['statistics'] ?? [];
        $totalCount = 0;

        foreach ($statistics as $statistic) {
            $totalCount += $statistic['count'];
        }

        return $totalCount;
    }
}
