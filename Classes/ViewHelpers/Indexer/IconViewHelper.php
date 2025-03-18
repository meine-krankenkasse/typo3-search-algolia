<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\ViewHelpers\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\IndexerRegistry;
use Override;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns the configured icon of the given indexer type.
 *
 * Example
 * =======
 *
 *    Inline:
 *
 *      {mkk:indexer.icon(type: 'page')}
 *
 *    Tag-based:
 *
 *      <mkk:indexer.icon type="page" />
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IconViewHelper extends AbstractViewHelper
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
    #[Override]
    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'type',
            'string',
            'The indexer type'
        );
    }

    /**
     * @return string
     */
    public function render(): string
    {
        return IndexerRegistry::getIndexerIcon($this->arguments['type'] ?? '');
    }
}
