<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service\Indexer\Fixtures;

use TYPO3\CMS\Core\Resource\Collection\StaticFileCollection;

/**
 * Test subject for FileIndexer's file collection iteration.
 *
 * StaticFileCollection::loadContents() populates the collection via
 * FileRepository::findByRelation(), a real database call. This subject
 * overrides it to a no-op so a unit test can populate the collection
 * directly via add() instead, keeping the collection's contents fully
 * under the test's control.
 */
final class StaticFileCollectionTestSubject extends StaticFileCollection
{
    public function loadContents(): void
    {
        // Intentionally empty: files are added directly via add() by the test.
    }
}
