<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Workspace;

use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordUpdateEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Listener that handles operations after a record is published.
 *
 * This class listens to the `AfterRecordPublishedEvent` and utilizes an
 * event dispatcher to trigger related search engine events, ensuring
 * synchronization between published data and search metadata. It primarily
 * manages the dispatching of `DataHandlerRecordUpdateEvent` to keep the
 * search index up-to-date with changes in the record data.
 *
 * Note: This listener requires typo3/cms-workspaces to be installed.
 * The event parameter is not type-hinted to allow the extension to work
 * without the workspaces extension installed. If workspaces is not loaded,
 * the listener will return early without doing anything.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class AfterRecordPublishedEventListener
{
    /**
     * Event dispatcher for triggering search-related events.
     *
     * This property stores the event dispatcher service that is used by concrete
     * implementations to dispatch DataHandler events (like DataHandlerRecordUpdateEvent
     * or DataHandlerRecordDeleteEvent) when file operations occur. These events
     * ensure that file metadata is properly indexed or removed from the search engine.
     *
     * @var EventDispatcherInterface
     */
    protected EventDispatcherInterface $eventDispatcher;

    /**
     * Constructor method for initializing the class.
     *
     * @param EventDispatcherInterface $eventDispatcher An instance of EventDispatcherInterface for event handling
     *
     * @return void
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
    ) {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Invokes the object as a function to handle the event.
     *
     * Note: The $event parameter is not type-hinted with AfterRecordPublishedEvent
     * to allow this extension to work without typo3/cms-workspaces installed.
     * The event will only be dispatched when workspaces extension is active.
     *
     * This method will return early if:
     * - The workspaces extension is not loaded
     * - The event object doesn't have the expected methods
     *
     * @param object $event The event instance containing information about the published record.
     *                      Expected to be TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent when workspaces is installed.
     *
     * @return void
     */
    public function __invoke(object $event): void
    {
        // Early return if workspaces extension is not loaded
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return;
        }

        // Verify that the event has the expected methods
        if (
            !method_exists($event, 'getTable')
            || !method_exists($event, 'getRecordId')
        ) {
            return;
        }

        // The event is guaranteed to be AfterRecordPublishedEvent at runtime
        /** @var \TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent $event */
        $this->eventDispatcher
            ->dispatch(
                new DataHandlerRecordUpdateEvent($event->getTable(), $event->getRecordId())
            );
    }
}
