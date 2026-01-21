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
 * Abstract base class for file event listeners in the search indexing system.
 *
 * This class provides common functionality for all event listeners that respond
 * to TYPO3 file events (added, deleted, moved, renamed, etc.). It:
 * - Defines the common dependencies needed by all file event listeners
 * - Provides access to the event dispatcher for dispatching search-related events
 * - Provides access to the file handler for working with file metadata
 *
 * Concrete implementations extend this class and implement the __invoke method
 * to handle specific file events, typically by retrieving the file's metadata UID
 * and dispatching appropriate DataHandler events to ensure that file metadata
 * is properly indexed or removed from the search engine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
abstract class AbstractAfterFileEventListener
{
    /**
     * Initializes the file event listener with required dependencies.
     *
     * This constructor injects the services needed for handling file events:
     * - The event dispatcher service is used to dispatch DataHandler events when
     *   file operations occur, ensuring that file metadata is properly indexed
     *   or removed from the search engine.
     * - The file handler service provides methods for working with file metadata,
     *   particularly for retrieving metadata UIDs that are needed to identify
     *   file metadata records in the database.
     *
     * Concrete implementations inherit these dependencies and use them in their
     * __invoke methods to handle specific file events.
     *
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher service for dispatching search-related events
     * @param FileHandler              $fileHandler     The file handler service for working with file metadata
     */
    public function __construct(
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected FileHandler $fileHandler,
    ) {
    }
}
