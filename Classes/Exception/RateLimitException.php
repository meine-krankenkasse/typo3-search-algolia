<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Exception;

use RuntimeException;

/**
 * Exception thrown when a search engine API enforces rate limiting.
 *
 * This exception is thrown when the search engine service (e.g., Algolia)
 * rejects requests because the application has exceeded the allowed number
 * of API calls within a specific time period. Rate limiting is a common
 * practice in API services to ensure fair usage and system stability.
 *
 * Common scenarios that may trigger this exception:
 * - Indexing a large number of records in a short time period
 * - Multiple concurrent indexing operations
 * - Exceeding the quota limits of your search service plan
 * - Aggressive reindexing schedules in the TYPO3 scheduler
 *
 * When this exception occurs, the application should:
 * - Implement exponential backoff and retry strategies
 * - Consider batching operations to reduce API call frequency
 * - Review indexing schedules to distribute load over time
 * - If persistent, consider upgrading the search service plan for higher limits
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class RateLimitException extends RuntimeException
{
}
