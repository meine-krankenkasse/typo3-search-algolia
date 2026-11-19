<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Traits;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\ContentIndexer;

/**
 * Trait for queueing the page that holds a changed content element.
 *
 * A page indexer that includes content elements in the page document needs
 * the page queued again when one of its content elements changes. The using
 * class provides the properties "recordRepository" and "recordHandler".
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
trait ContentElementPageTrait
{
    /**
     * Queues the page of the given content element again, so that its index entry is rebuilt on the next indexing run.
     *
     * Nothing happens if the content element has no page, for example because the record is gone.
     *
     * @param int $rootPageId        The root page ID of the indexing context
     * @param int $contentElementUid The UID of the changed content element
     */
    private function processPageOfContentElement(int $rootPageId, int $contentElementUid): void
    {
        $pageId = $this->recordRepository
            ->findPid(
                ContentIndexer::TABLE,
                $contentElementUid,
            );

        if ($pageId !== false) {
            $this->recordHandler
                ->processPageOfContentElement($rootPageId, $pageId);
        }
    }
}
