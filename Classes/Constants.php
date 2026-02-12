<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia;

/**
 * Constants for the TYPO3 Algolia Search extension.
 *
 * This class provides centralized storage for constant values used throughout
 * the extension. It helps maintain consistency and makes it easier to update
 * values in a single location rather than throughout the codebase.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class Constants
{
    /**
     * The extension key used for configuration and identification.
     *
     * This constant defines the unique identifier for the extension within TYPO3.
     * It is used for accessing extension configuration, registering services,
     * and as a namespace for extension-specific settings in the TYPO3 configuration.
     *
     * @var string
     */
    public const string EXTENSION_NAME = 'typo3_search_algolia';

    /**
     * The maximum depth for recursive page tree traversal.
     *
     * This constant defines the maximum number of levels to traverse when
     * recursively collecting subpages for indexing operations.
     *
     * @var int
     */
    public const int MAX_PAGE_TREE_DEPTH = 99;
}
