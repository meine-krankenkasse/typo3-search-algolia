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
 * Exception thrown when the CLI/scheduler TypoScript fallback cannot read the
 * extension's bundled setup.typoscript from disk.
 *
 * This is thrown by TypoScriptService::getCliFallbackTypoScript() when no
 * request is bound (e.g. the index queue worker command) and the bundled
 * TypoScript file is missing or unreadable. It is deliberately a dedicated
 * type rather than a bare RuntimeException, so callers (e.g. the worker
 * command's per-item error handling) can catch exactly this environment-level
 * failure without also swallowing unrelated RuntimeExceptions raised deeper
 * in the indexing call chain.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class CliFallbackTypoScriptUnreadableException extends RuntimeException
{
}
