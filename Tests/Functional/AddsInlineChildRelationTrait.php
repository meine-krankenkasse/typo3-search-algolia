<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional;

use Throwable;

/**
 * Adds an inline relation from content elements to content elements to the TCA
 * of a functional test, with the field that holds the UID of the parent, the way
 * a content element type of a site package defines its child elements.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
trait AddsInlineChildRelationTrait
{
    /**
     * Makes the field tx_test_children of tt_content an inline relation to the
     * content elements whose field tx_test_parent holds the UID of the parent.
     *
     * @return void
     */
    private function addInlineChildRelation(): void
    {
        try {
            $this->getConnectionPool()
                ->getConnectionForTable('tt_content')
                ->executeStatement('ALTER TABLE tt_content ADD COLUMN tx_test_parent INT DEFAULT 0 NOT NULL');
        } catch (Throwable) {
            // The column exists from an earlier test of the same run
        }

        $GLOBALS['TCA']['tt_content']['columns']['tx_test_children'] = [
            'config' => [
                'type'          => 'inline',
                'foreign_table' => 'tt_content',
                'foreign_field' => 'tx_test_parent',
            ],
        ];
    }
}
