<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MeineKrankenkasse\Typo3SearchAlgolia\Controller\EnqueueOneController;

return [
    // Enqueues a single file
    'algolia_enqueue_one' => [
        'path'   => '/module/meine-krankenkasse/typo3-search/enqueueOne',
        'target' => EnqueueOneController::class . '::mainAction',
    ],
];
