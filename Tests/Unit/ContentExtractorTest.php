<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit;

use MeineKrankenkasse\Typo3SearchAlgolia\ContentExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentExtractor.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(ContentExtractor::class)]
class ContentExtractorTest extends TestCase
{
    /**
     * Tests that sanitizeContent() strips inline <script> blocks and their content
     * from the HTML string, leaving only the surrounding text joined by a space.
     */
    #[Test]
    public function sanitizeContentRemovesScriptBlocks(): void
    {
        $html = 'Hello <script>alert("xss")</script> World';

        self::assertSame('Hello World', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() strips inline <style> blocks and their CSS content
     * from the HTML string, leaving only the surrounding text joined by a space.
     */
    #[Test]
    public function sanitizeContentRemovesStyleBlocks(): void
    {
        $html = 'Hello <style>.foo { color: red; }</style> World';

        self::assertSame('Hello World', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() correctly removes multiline <script> and <style> blocks
     * including their full content spanning multiple lines, returning only the visible
     * text content with normalized whitespace.
     */
    #[Test]
    public function sanitizeContentRemovesMultilineScriptAndStyle(): void
    {
        $html = <<<'HTML'
            <p>Before</p>
            <script type="text/javascript">
                var x = 1;
                console.log(x);
            </script>
            <style type="text/css">
                body { margin: 0; }
            </style>
            <p>After</p>
            HTML;

        self::assertSame('Before After', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() inserts spaces between adjacent block-level elements
     * to prevent text from separate paragraphs being concatenated without a separator.
     */
    #[Test]
    public function sanitizeContentPreventsWordConcatenation(): void
    {
        $html = '<p>First</p><p>Second</p>';

        self::assertSame('First Second', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() converts non-breaking space HTML entities (&nbsp;)
     * into regular spaces and collapses consecutive non-breaking spaces into a
     * single space character.
     */
    #[Test]
    public function sanitizeContentConvertsNonBreakingSpaces(): void
    {
        $html = 'Word1&nbsp;Word2&nbsp;&nbsp;Word3';

        self::assertSame('Word1 Word2 Word3', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() removes all HTML tags including nested formatting tags
     * like <strong> and <em>, as well as structural tags like <div> and <h1>,
     * returning only the plain text content with proper spacing.
     */
    #[Test]
    public function sanitizeContentStripsAllHtmlTags(): void
    {
        $html = '<div class="wrapper"><h1>Title</h1><p>Content with <strong>bold</strong> and <em>italic</em></p></div>';

        self::assertSame('Title Content with bold and italic', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() decodes HTML entities such as &eacute;, &amp;,
     * &lt;, and &gt; into their corresponding UTF-8 characters after stripping tags.
     */
    #[Test]
    public function sanitizeContentDecodesHtmlEntities(): void
    {
        $html = 'Caf&eacute; &amp; Restaurant &lt;Gourmet&gt;';

        self::assertSame('Café & Restaurant <Gourmet>', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() normalizes all forms of whitespace (multiple spaces,
     * newlines, tabs, carriage returns) into single space characters, producing
     * a clean single-line string.
     */
    #[Test]
    public function sanitizeContentNormalizesWhitespace(): void
    {
        $html = "Word1   Word2\n\nWord3\t\tWord4\r\nWord5";

        self::assertSame('Word1 Word2 Word3 Word4 Word5', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() trims leading and trailing whitespace from the final
     * result, including whitespace that was outside or inside HTML tags at the
     * boundaries of the input string.
     */
    #[Test]
    public function sanitizeContentTrimsLeadingAndTrailingWhitespace(): void
    {
        $html = '   <p> Content </p>   ';

        self::assertSame('Content', ContentExtractor::sanitizeContent($html));
    }

    /**
     * Tests that sanitizeContent() returns an empty string when given an empty string
     * as input, confirming it handles the edge case without errors.
     */
    #[Test]
    public function sanitizeContentReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', ContentExtractor::sanitizeContent(''));
    }

    /**
     * Tests that sanitizeContent() correctly processes a full HTML document including
     * DOCTYPE, head section with script/style resources, and body with navigation,
     * main content, and footer. Verifies that visible text is preserved, &nbsp;
     * entities are converted, HTML entities are decoded, and all tags, scripts,
     * and styles are completely removed from the output.
     */
    #[Test]
    public function sanitizeContentHandlesComplexDocument(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
                <title>Test</title>
                <script src="app.js"></script>
                <style>body { font-size: 14px; }</style>
            </head>
            <body>
                <nav>Navigation</nav>
                <main>
                    <h1>Page&nbsp;Title</h1>
                    <p>First paragraph with <a href="#">link</a>.</p>
                    <p>Second paragraph with <strong>bold</strong> &amp; <em>italic</em>.</p>
                </main>
                <footer>&copy; 2024</footer>
            </body>
            </html>
            HTML;

        $result = ContentExtractor::sanitizeContent($html);

        self::assertStringContainsString('Navigation', $result);
        self::assertStringContainsString('Page Title', $result);
        self::assertStringContainsString('First paragraph with link', $result);
        self::assertStringContainsString('bold & italic', $result);
        self::assertStringNotContainsString('<', $result);
        self::assertStringNotContainsString('>', $result);
        self::assertStringNotContainsString('font-size', $result);
        self::assertStringNotContainsString('app.js', $result);
    }

    /**
     * Tests that sanitizeContent() handles nested or overlapping script tags gracefully,
     * ensuring that the content between the outermost <script> opening and closing
     * tags is fully removed while preserving the text before and after the script block.
     */
    #[Test]
    public function sanitizeContentHandlesNestedScriptTags(): void
    {
        $html = 'Before<script>var s = "<script>nested</script>";</script>After';

        $result = ContentExtractor::sanitizeContent($html);

        self::assertStringContainsString('Before', $result);
        self::assertStringContainsString('After', $result);
        self::assertStringNotContainsString('nested', $result);
    }

    /**
     * Tests that sanitizeContent() returns plain text input unchanged when the string
     * contains no HTML tags, entities, or special characters that require processing.
     */
    #[Test]
    public function sanitizeContentHandlesPlainTextWithoutTags(): void
    {
        $plainText = 'This is plain text without any HTML tags.';

        self::assertSame($plainText, ContentExtractor::sanitizeContent($plainText));
    }

    // -----------------------------------------------------------------------
    // UTF-8 sanitization (integrated in sanitizeContent)
    // -----------------------------------------------------------------------

    /**
     * Tests that sanitizeContent() strips invalid UTF-8 byte sequences from a string,
     * producing output that is valid UTF-8 and can be safely passed to json_encode().
     * This is a regression test for the "json_encode error: Malformed UTF-8 characters,
     * possibly incorrectly encoded" bug that occurred when indexing PDF files.
     */
    #[Test]
    public function sanitizeContentStripsInvalidUtf8ByteSequences(): void
    {
        // \xC0\xAF is an overlong encoding (invalid UTF-8),
        // \x80 is a continuation byte without a leading byte (also invalid UTF-8).
        $malformed = "Valid start \xC0\xAF middle \x80 end";

        // Precondition: the input IS actually malformed
        self::assertFalse(mb_check_encoding($malformed, 'UTF-8'), 'Precondition: input must contain invalid UTF-8');
        self::assertFalse(json_encode($malformed), 'Precondition: json_encode must fail on malformed input');

        $sanitized = ContentExtractor::sanitizeContent($malformed);

        self::assertTrue(mb_check_encoding($sanitized, 'UTF-8'), 'Output must be valid UTF-8');
        self::assertNotFalse(json_encode($sanitized), 'json_encode must succeed after cleaning');
    }

    /**
     * Tests that sanitizeContent() preserves already-valid UTF-8 multi-byte characters
     * like umlauts and CJK characters without mangling them.
     */
    #[Test]
    public function sanitizeContentPreservesValidUtf8MultiByteCharacters(): void
    {
        $validUtf8 = 'Ärzte für Überweisung — 日本語 🔍';

        self::assertSame($validUtf8, ContentExtractor::sanitizeContent($validUtf8));
    }

    // -----------------------------------------------------------------------
    // truncateToByteLength()
    // -----------------------------------------------------------------------

    /**
     * Tests that truncateToByteLength() returns the content unchanged when
     * it is already shorter than the given byte limit.
     */
    #[Test]
    public function truncateToByteLengthReturnsContentUnchangedWhenUnderLimit(): void
    {
        $content = 'Short content';

        self::assertSame($content, ContentExtractor::truncateToByteLength($content, 1000));
    }

    /**
     * Tests that truncateToByteLength() returns the content unchanged when
     * its byte length exactly matches the given limit.
     */
    #[Test]
    public function truncateToByteLengthReturnsContentUnchangedWhenExactlyAtLimit(): void
    {
        $content = str_repeat('a', 10);

        self::assertSame($content, ContentExtractor::truncateToByteLength($content, 10));
    }

    /**
     * Tests that truncateToByteLength() cuts plain ASCII content down to
     * exactly the given number of bytes.
     */
    #[Test]
    public function truncateToByteLengthCutsAsciiContentToExactByteLength(): void
    {
        $content = str_repeat('a', 20);

        $result = ContentExtractor::truncateToByteLength($content, 10);

        self::assertSame(str_repeat('a', 10), $result);
        self::assertSame(10, strlen($result));
    }

    /**
     * Tests that truncateToByteLength() never splits a multi-byte UTF-8
     * character in the middle, even when the byte limit falls inside one.
     * Splitting mid-character would produce invalid UTF-8 and break
     * json_encode() when the document is sent to the search engine.
     */
    #[Test]
    public function truncateToByteLengthDoesNotSplitMultiByteCharacter(): void
    {
        // Each 'ä' is 2 bytes in UTF-8, so a limit of 11 bytes falls exactly
        // in the middle of the 6th character.
        $content = str_repeat('ä', 10);

        $result = ContentExtractor::truncateToByteLength($content, 11);

        self::assertTrue(mb_check_encoding($result, 'UTF-8'), 'Result must be valid UTF-8');
        self::assertLessThanOrEqual(11, strlen($result));
        self::assertSame(str_repeat('ä', 5), $result);
    }

    /**
     * Tests that truncateToByteLength() returns an empty string when given
     * an empty string, regardless of the byte limit.
     */
    #[Test]
    public function truncateToByteLengthReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', ContentExtractor::truncateToByteLength('', 100));
    }

    /**
     * Tests that truncateToByteLength() returns an empty string when the
     * byte limit itself is zero, the degenerate case of the limit.
     */
    #[Test]
    public function truncateToByteLengthReturnsEmptyStringWhenMaxBytesIsZero(): void
    {
        self::assertSame('', ContentExtractor::truncateToByteLength('non-empty content', 0));
    }

    // -----------------------------------------------------------------------
    // removeRecurringLines()
    // -----------------------------------------------------------------------

    /**
     * Tests that removeRecurringLines() leaves the pages untouched when the
     * number of pages is below the minimum required to reliably detect a
     * recurring header or footer.
     */
    #[Test]
    public function removeRecurringLinesKeepsAllLinesBelowMinimumPageCount(): void
    {
        $pages = [
            "Recurring Header\nFirst page content.",
            "Recurring Header\nSecond page content.",
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringContainsString('Recurring Header', $result);
        self::assertStringContainsString('First page content.', $result);
        self::assertStringContainsString('Second page content.', $result);
    }

    /**
     * Tests that removeRecurringLines() strips a line that appears as the
     * first line on every page once the minimum page count is reached.
     */
    #[Test]
    public function removeRecurringLinesStripsRecurringHeaderLine(): void
    {
        $pages = [
            "Satzung der Krankenkasse\nContent of page one.",
            "Satzung der Krankenkasse\nContent of page two.",
            "Satzung der Krankenkasse\nContent of page three.",
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringNotContainsString('Satzung der Krankenkasse', $result);
        self::assertStringContainsString('Content of page one.', $result);
        self::assertStringContainsString('Content of page two.', $result);
        self::assertStringContainsString('Content of page three.', $result);
    }

    /**
     * Tests that removeRecurringLines() strips a line that appears as the
     * last line on every page (a footer), not just header lines.
     */
    #[Test]
    public function removeRecurringLinesStripsRecurringFooterLine(): void
    {
        $pages = [
            "Content of page one.\nConfidential internal document",
            "Content of page two.\nConfidential internal document",
            "Content of page three.\nConfidential internal document",
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringNotContainsString('Confidential internal document', $result);
        self::assertStringContainsString('Content of page one.', $result);
    }

    /**
     * Tests that removeRecurringLines() keeps a line that only appears on a
     * minority of pages, since it does not meet the recurrence threshold
     * and is therefore not reliably a header or footer.
     */
    #[Test]
    public function removeRecurringLinesKeepsLineBelowFrequencyThreshold(): void
    {
        $pages = [
            "Occasional Note\nContent of page one.",
            'Content of page two.',
            'Content of page three.',
            'Content of page four.',
            'Content of page five.',
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringContainsString('Occasional Note', $result);
    }

    /**
     * Tests that a line classified as recurring boilerplate (because it
     * appears at the edge of most pages) is only removed from the pages
     * where it actually occupies an edge position. On a page where the
     * same exact text happens to occur in the interior instead, it is
     * genuine body content there and must be kept, even though the
     * removal set was built from other pages' edge occurrences.
     */
    #[Test]
    public function removeRecurringLinesKeepsInteriorOccurrenceOfALineThatIsRecurringElsewhere(): void
    {
        $pages = [
            "Page 1 body.\nMore body text line.\nConfidential internal use only",
            "Page 2 body.\nMore body text line.\nConfidential internal use only",
            "Page 3 body.\nMore body text line.\nConfidential internal use only",
            "Page 4 body.\nMore body text line.\nConfidential internal use only",
            "Intro line.\nAs stated above,\nConfidential internal use only\nis the classification used here.\nFinal line.",
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringContainsString('is the classification used here.', $result);
        self::assertStringContainsString('As stated above,', $result);
        self::assertStringContainsString('Confidential internal use only', $result);
    }

    /**
     * Tests that removeRecurringLines() only inspects the leading and
     * trailing lines of each page for recurrence, so an identical sentence
     * appearing in the middle of every page (not a header or footer) is
     * preserved rather than removed as if it were boilerplate.
     */
    #[Test]
    public function removeRecurringLinesKeepsIdenticalInteriorLine(): void
    {
        $pages = [
            "Page 1 line 1\nPage 1 line 2\nSame middle sentence.\nPage 1 line 4\nPage 1 line 5",
            "Page 2 line 1\nPage 2 line 2\nSame middle sentence.\nPage 2 line 4\nPage 2 line 5",
            "Page 3 line 1\nPage 3 line 2\nSame middle sentence.\nPage 3 line 4\nPage 3 line 5",
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringContainsString('Same middle sentence.', $result);
    }

    /**
     * Tests that removeRecurringLines() strips a line that recurs on exactly
     * the minimum frequency ratio (3 of 5 pages = 0.6), the inclusive
     * boundary of the default threshold.
     */
    #[Test]
    public function removeRecurringLinesStripsLineAtExactFrequencyThreshold(): void
    {
        $pages = [
            "Shared Header\nContent of page one.",
            "Shared Header\nContent of page two.",
            "Shared Header\nContent of page three.",
            'Content of page four.',
            'Content of page five.',
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringNotContainsString('Shared Header', $result);
        self::assertStringContainsString('Content of page four.', $result);
    }

    /**
     * Tests that a page short enough for its leading and trailing edge
     * slices to overlap (e.g. a single-line page) only counts its line once
     * towards the recurrence frequency, not once per overlapping slice.
     * Without that de-duplication, a line below the frequency threshold
     * could be miscounted as recurring often enough to be stripped.
     */
    #[Test]
    public function removeRecurringLinesDoesNotDoubleCountLinesOnAShortOverlappingPage(): void
    {
        $pages = [
            'Short Boilerplate',
            "Short Boilerplate\nContent of page two.",
            'Content of page three.',
            'Content of page four.',
            'Content of page five.',
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringContainsString('Short Boilerplate', $result);
    }

    /**
     * Tests that a blank page mixed in among pages with a recurring header
     * neither crashes nor prevents the header from being detected, as long
     * as the header still meets the frequency threshold among all pages.
     */
    #[Test]
    public function removeRecurringLinesTreatsBlankPageAsHavingNoEdgeLines(): void
    {
        $pages = [
            "Recurring Header\nContent of page one.",
            "Recurring Header\nContent of page two.",
            "Recurring Header\nContent of page three.",
            "Recurring Header\nContent of page four.",
            "   \n\t  ",
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringNotContainsString('Recurring Header', $result);
        self::assertStringContainsString('Content of page one.', $result);
    }

    /**
     * Tests that a recurring line at the second line of each page (not just
     * the very first line) is still detected, pinning the configured edge
     * width rather than only ever exercising a width of one.
     */
    #[Test]
    public function removeRecurringLinesStripsLineAtSecondLinePosition(): void
    {
        $pages = [
            "Page 1 title.\nRecurring Subtitle\nBody content one.",
            "Page 2 title.\nRecurring Subtitle\nBody content two.",
            "Page 3 title.\nRecurring Subtitle\nBody content three.",
        ];

        $result = ContentExtractor::removeRecurringLines($pages, 3);

        self::assertStringNotContainsString('Recurring Subtitle', $result);
        self::assertStringContainsString('Page 1 title.', $result);
        self::assertStringContainsString('Body content one.', $result);
    }

    /**
     * Tests that removeRecurringLines() does not strip a line when called
     * with its default $minPages, using fewer pages than that default
     * requires, pinning the default's exact value rather than only ever
     * exercising it via an explicitly passed, equal argument.
     */
    #[Test]
    public function removeRecurringLinesKeepsAllLinesBelowDefaultMinimumPageCount(): void
    {
        $pages = [
            "Recurring Header\nFirst page content.",
            "Recurring Header\nSecond page content.",
        ];

        $result = ContentExtractor::removeRecurringLines($pages);

        self::assertStringContainsString('Recurring Header', $result);
    }
}
