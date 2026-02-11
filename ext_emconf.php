<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

$EM_CONF['typo3_search_algolia'] = [
    'title'          => 'Meine Krankenkasse: TYPO3 Search Algolia',
    'description'    => 'A TYPO3 extension that integrates Algolia search into your website by indexing TYPO3 content for lightning-fast, relevant search results.',
    'category'       => 'module',
    'author'         => 'Rico Sonntag',
    'author_email'   => 'rico.sonntag@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state'          => 'stable',
    'version'        => '1.2.3',
    'constraints'    => [
        'depends' => [
            'typo3' => '12.4.0-12.99.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
