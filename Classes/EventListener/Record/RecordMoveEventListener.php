<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\DataHandling\RecordHandler;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\DataHandlerRecordMoveEvent;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * The record move event listener. This event listener is called when a record is moved.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RecordMoveEventListener
{
    /**
     * @var RecordHandler
     */
    private readonly RecordHandler $recordHandler;

    /**
     * @var DataHandlerRecordMoveEvent
     */
    private DataHandlerRecordMoveEvent $event;

    /**
     * Constructor.
     *
     * @param RecordHandler $recordHandler
     */
    public function __construct(RecordHandler $recordHandler)
    {
        $this->recordHandler = $recordHandler;
    }

    /**
     * Invoke the event listener.
     *
     * @param DataHandlerRecordMoveEvent $event
     */
    public function __invoke(DataHandlerRecordMoveEvent $event): void
    {
        $this->event = $event;
        DebuggerUtility::var_dump($this->event);
        exit;
        // Source and target parent ID are the same => Do nothing
        if ($this->event->getTargetPid() === $this->event->getPreviousPid()) {
            return;
        }

        // TODO Check if record is enabled before adding to queue and index

        // Determine the root page ID for the event record
        $rootPageId = $this->recordHandler
            ->getRecordRootPageId(
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        $this->recordHandler
            ->updateRecordInQueue(
                $rootPageId,
                $this->event->getTable(),
                $this->event->getRecordUid()
            );

        // Update previous page
        if (
            $this->isContentElementUpdate()
            && ($this->event->getPreviousPid() !== null)
        ) {
            $this->recordHandler
                ->processPageOfContentElement(
                    $rootPageId,
                    $this->event->getPreviousPid()
                );
        }
    }

    /**
     * Returns TRUE if a content element update is performed.
     *
     * @return bool
     */
    private function isContentElementUpdate(): bool
    {
        return $this->event->getTable() === 'tt_content';
    }
}
