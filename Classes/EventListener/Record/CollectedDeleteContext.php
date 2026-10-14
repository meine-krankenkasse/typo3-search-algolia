<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Record;

use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;

/**
 * What the delete listener collects about a record while it still exists, to remove the
 * documents of the record and of everything that is deleted with it once it is gone.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class CollectedDeleteContext
{
    /**
     * Constructor method for the collected context.
     *
     * @param int                                                                 $rootPageId         The root page UID that establishes the indexing context of the record
     * @param int|false                                                           $pageId             The page of a content element, FALSE for any other record
     * @param int[]                                                               $contentElementUids The content elements that are deleted with a page
     * @param int[]                                                               $subPageIds         The subpages that are deleted with a page
     * @param array<string, int[]>                                                $additionalRecords  The UIDs of the dependent records by table
     * @param array<string, array<int, array{IndexingService, IndexerInterface}>> $targets            The indexing services and indexers that were resolved before the deletion, by table
     */
    public function __construct(
        public int $rootPageId,
        public int|false $pageId,
        public array $contentElementUids,
        public array $subPageIds,
        public array $additionalRecords,
        public array $targets,
    ) {
    }
}
