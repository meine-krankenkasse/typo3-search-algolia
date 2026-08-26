<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

$EM_CONF['typo3_search_algolia'] = [
    'title'          => 'Meine Krankenkasse: TYPO3 Search Algolia',
    'description'    => 'A TYPO3 extension that integrates Algolia search into your website by indexing TYPO3 content for lightning-fast, relevant search results.',
    'category'       => 'module',
    'author'         => 'mkk – meine krankenkasse',
    'author_email'   => 'digital@meine-krankenkasse.de',
    'author_company' => 'mkk – meine krankenkasse',
    'state'          => 'stable',
    'version'        => '1.3.4',
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
