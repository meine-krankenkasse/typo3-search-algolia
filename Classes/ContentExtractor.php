<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function html_entity_decode;
use function implode;
use function in_array;
use function mb_convert_encoding;
use function mb_strcut;
use function preg_replace;
use function str_replace;
use function strip_tags;
use function strlen;
use function trim;

use const ARRAY_FILTER_USE_BOTH;

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
     * The share of pages a line must appear on to count as a recurring
     * header/footer, used by removeRecurringLines().
     */
    private const float MIN_RECURRING_FREQUENCY_RATIO = 0.6;

    /**
     * How many leading and trailing lines of each page removeRecurringLines()
     * inspects for recurrence.
     */
    private const int EDGE_LINE_COUNT = 2;

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

    /**
     * Truncates the given content to fit within a maximum number of bytes.
     *
     * This method keeps extracted content (e.g. from large PDF files) within
     * the record size limits enforced by search engines like Algolia. The
     * cut is made on a UTF-8 character boundary, so a multi-byte character
     * is never split in half, which would otherwise produce invalid UTF-8
     * and break json_encode() when the document is sent to the search engine.
     *
     * @param string $content  The content to truncate
     * @param int    $maxBytes The maximum allowed length in bytes
     *
     * @return string The original content, or a UTF-8-safe prefix of it that
     *                fits within $maxBytes bytes
     */
    public static function truncateToByteLength(string $content, int $maxBytes): string
    {
        if (strlen($content) <= $maxBytes) {
            return $content;
        }

        return mb_strcut(
            $content,
            0,
            $maxBytes,
            'UTF-8'
        );
    }

    /**
     * Removes lines that recur across the leading or trailing lines of most
     * pages, such as a repeated document title or a confidentiality footer.
     *
     * This method is used to strip boilerplate content that PDF extraction
     * repeats once per page before that content is added to the search index.
     * Only the first and last few lines of each page are inspected, so a
     * sentence that happens to be identical in the middle of every page
     * (not a header or footer) is left untouched. Detection is skipped
     * entirely for documents with too few pages to tell a genuine header or
     * footer apart from coincidentally identical body text.
     *
     * @param string[] $pageTexts The raw extracted text of each page, in page order
     * @param int      $minPages  The minimum number of pages required to attempt detection
     *
     * @return string The pages joined into a single string with recurring lines removed
     */
    public static function removeRecurringLines(array $pageTexts, int $minPages = 3): string
    {
        $pageCount = count($pageTexts);

        if ($pageCount < $minPages) {
            return implode(' ', $pageTexts);
        }

        $pageLines = array_map(
            static fn (string $pageText): array => self::extractNonEmptyLines($pageText),
            $pageTexts
        );

        $recurringLines = self::findRecurringEdgeLines($pageLines);

        $cleanedPages = array_map(
            static fn (array $lines): string => self::removeRecurringLinesFromPage($lines, $recurringLines),
            $pageLines
        );

        return implode(' ', $cleanedPages);
    }

    /**
     * Removes the given recurring lines from a single page, but only where
     * they appear within that page's own leading or trailing edge lines.
     *
     * A line elsewhere on the page that happens to match is genuine body
     * content on that page, not a header or footer, and is left untouched,
     * even though the exact same text was classified as boilerplate because
     * of where it appeared on other pages.
     *
     * @param string[] $lines          A single page's lines, as produced by extractNonEmptyLines()
     * @param string[] $recurringLines The lines considered boilerplate, as produced by findRecurringEdgeLines()
     *
     * @return string The page's lines joined back together with the recurring edge lines removed
     */
    private static function removeRecurringLinesFromPage(array $lines, array $recurringLines): string
    {
        $lineCount = count($lines);

        $keptLines = array_filter(
            $lines,
            static function (string $line, int $index) use ($recurringLines, $lineCount): bool {
                $isEdgeLine = ($index < self::EDGE_LINE_COUNT) || ($index >= $lineCount - self::EDGE_LINE_COUNT);

                return !($isEdgeLine && in_array($line, $recurringLines, true));
            },
            ARRAY_FILTER_USE_BOTH
        );

        return implode(' ', $keptLines);
    }

    /**
     * Splits a page's raw text into its non-empty, trimmed lines.
     *
     * @param string $pageText The raw extracted text of a single page
     *
     * @return string[] The page's lines, trimmed and with empty lines removed
     */
    private static function extractNonEmptyLines(string $pageText): array
    {
        $lines = array_map(
            static fn (string $line): string => trim($line),
            explode("\n", $pageText)
        );

        return array_values(
            array_filter(
                $lines,
                static fn (string $line): bool => $line !== ''
            )
        );
    }

    /**
     * Determines which lines recur often enough among the leading and
     * trailing lines of the given pages to be treated as a header or footer.
     *
     * @param array<int, string[]> $pageLines Each page's lines, as produced by extractNonEmptyLines()
     *
     * @return string[] The lines that recur often enough to be considered boilerplate
     */
    private static function findRecurringEdgeLines(array $pageLines): array
    {
        $pageCount = count($pageLines);
        $frequency = [];

        foreach ($pageLines as $lines) {
            $edgeLines = array_unique([
                ...array_slice($lines, 0, self::EDGE_LINE_COUNT),
                ...array_slice($lines, -self::EDGE_LINE_COUNT),
            ]);

            foreach ($edgeLines as $edgeLine) {
                $frequency[$edgeLine] = ($frequency[$edgeLine] ?? 0) + 1;
            }
        }

        $recurringLines = [];

        foreach ($frequency as $line => $occurrences) {
            if (($occurrences / $pageCount) >= self::MIN_RECURRING_FREQUENCY_RATIO) {
                // PHP coerces a purely-numeric array key (e.g. "12345") to int,
                // so $line must be cast back to string here. Without it, a
                // numeric recurring line would never match the strict
                // in_array() comparison in removeRecurringLinesFromPage(),
                // which always compares against a string.
                $recurringLines[] = (string) $line;
            }
        }

        return $recurringLines;
    }
}
