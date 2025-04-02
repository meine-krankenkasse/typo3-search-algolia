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
 * Class ContentExtractor.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ContentExtractor
{
    /**
     * Removes all unwanted elements from the given HTML string.
     *
     * @param string $content
     *
     * @return string
     */
    public static function cleanHtml(string $content): string
    {
        // Remove JavaScript and internal CSS styles
        $content = (string) preg_replace(
            '@<(script|style)[^>]*?>.*?</\\1>@si',
            '',
            $content
        );

        // Prevent word concatenation when HTML tags are subsequently removed
        $content = str_replace(['<', '>'], [' <', '> '], $content);

        // Replace line breaks and tabs with single spaces
        $content = str_replace(["\t", "\n", "\r", '&nbsp;'], ' ', $content);

        // Remove HTML tags
        $content = strip_tags($content);

        // Convert HTML entities to their corresponding characters
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');

        // Remove leading and trailing spaces
        return trim($content);
    }
}
