<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Fixtures\Service\Indexer;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\PageIndexer;
use Override;

/**
 * Test double for a custom indexer written against 3.0.0: it overrides
 * initQueueItemRecords() with the exact 3.0.0 signature and knows nothing
 * about a record limit, so it always returns its full fixed record set.
 *
 * Loading this class at all proves the 3.0.0 override signature is still
 * compatible with AbstractIndexer.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class LegacyOverridePageIndexer extends PageIndexer
{
    /**
     * Returns a fixed set of three queue item records, ignoring any limit.
     *
     * @param int[] $recordUids Unused, kept to match the 3.0.0 signature
     *
     * @return array<array-key, array<string, int|string>> The fixed queue item records
     */
    #[Override]
    protected function initQueueItemRecords(array $recordUids = []): array
    {
        return [
            ['record_uid' => '5', 'table_name' => 'pages', 'service_uid' => 1, 'changed' => 0, 'priority' => 0],
            ['record_uid' => '4', 'table_name' => 'pages', 'service_uid' => 1, 'changed' => 0, 'priority' => 0],
            ['record_uid' => '3', 'table_name' => 'pages', 'service_uid' => 1, 'changed' => 0, 'priority' => 0],
        ];
    }
}
