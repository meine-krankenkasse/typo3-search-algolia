<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

/**
 * Plain mutable holder recording the arguments LimitCapturingIndexer observed,
 * shared by reference across every clone withIndexingService()/
 * withExcludeHiddenPages() produces (see LimitCapturingIndexer), so the test
 * can inspect what was captured after AttributeOverviewModuleController has
 * finished re-scoping and calling the spied indexer through its whole
 * immutable with*() chain.
 */
final class LimitCapture
{
    /**
     * The $limit argument IndexerInterface::findRecordUidsInScope() was
     * actually called with, or NULL if it was never called.
     */
    public ?int $capturedLimit = null;

    /**
     * The number of record UIDs IndexerInterface::findRecordUidsInScope()
     * actually returned, or NULL if it was never called.
     */
    public ?int $capturedRecordUidCount = null;
}
