<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Resource;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\FileHandler;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The abstract class for the AfterFile*EventListeners.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractAfterFileEventListener
{
    /**
     * @var EventDispatcherInterface
     */
    protected readonly EventDispatcherInterface $eventDispatcher;

    /**
     * @var FileHandler
     */
    protected FileHandler $fileHandler;

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param FileHandler              $fileHandler
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        FileHandler $fileHandler,
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->fileHandler     = $fileHandler;
    }
}
