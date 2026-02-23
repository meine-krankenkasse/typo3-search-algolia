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
}
