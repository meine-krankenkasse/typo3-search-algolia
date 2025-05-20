<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Exception;

use TYPO3\CMS\Core\Resource\Exception;

/**
 * Exception thrown when a required search engine configuration is missing or invalid.
 *
 * This exception is thrown in situations where the extension requires specific
 * configuration settings to operate properly, but these settings are either:
 * - Completely missing from the configuration
 * - Present but with invalid values
 * - Incomplete or insufficient for the requested operation
 *
 * Common scenarios include:
 * - Missing API credentials for the search service
 * - Invalid index name configuration
 * - Missing required field mappings
 * - Attempting to use a search engine that hasn't been properly configured
 *
 * When this exception occurs, administrators should check the extension configuration
 * in the TYPO3 backend and ensure all required settings are properly defined.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class MissingConfigurationException extends Exception
{
}
