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
 * Utility class for extracting and cleaning content for search indexing.
 *
 * This class provides methods to process HTML content by removing unwanted
 * elements (scripts, styles, HTML tags) and normalizing the text to make it
 * suitable for search indexing. It helps ensure that only relevant textual
 * content is indexed while removing markup and other non-searchable elements.
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
     * This method processes HTML content to make it suitable for search indexing by:
     * 1. Removing JavaScript and CSS style blocks
     * 2. Adding spaces around HTML tags to prevent word concatenation
     * 3. Converting non-breaking spaces to regular spaces
     * 4. Stripping all HTML tags
     * 5. Converting HTML entities to their corresponding characters
     * 6. Normalizing whitespace (replacing multiple spaces, line breaks, tabs with a single space)
     * 7. Sanitizing invalid UTF-8 byte sequences to prevent json_encode errors
     * 8. Trimming leading and trailing whitespace
     *
     * @param string $content The HTML content to be cleaned
     *
     * @return string The cleaned plain text content suitable for indexing
     */
    public static function sanitizeContent(string $content): string
    {
        // Remove JavaScript and internal CSS styles
        $content = (string) preg_replace(
            '#<(script|style)[^>]*?>.*?</\\1>#msi',
            '',
            $content
        );

        // Prevent word concatenation when HTML tags are subsequently removed
        $content = str_replace(['<', '>'], [' <', '> '], $content);

        // Replace "non-breaking space" with a single space
        $content = str_replace('&nbsp;', ' ', $content);

        // Remove HTML tags
        $content = strip_tags($content);

        // Convert HTML entities to their corresponding characters
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');

        // Replace multiple spaces, \r, \n and \t with a single space
        $content = (string) preg_replace('/\s+/', ' ', $content);

        // Ensure valid UTF-8 encoding by stripping invalid byte sequences. This prevents
        // "json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded"
        // exceptions, e.g. when indexing PDF content extracted by smalot/pdfparser.
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        // Remove leading and trailing spaces
        return trim($content);
    }
}
