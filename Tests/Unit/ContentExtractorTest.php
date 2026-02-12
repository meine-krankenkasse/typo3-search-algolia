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
     * Tests that cleanHtml() strips inline <script> blocks and their content
     * from the HTML string, leaving only the surrounding text joined by a space.
     */
    #[Test]
    public function cleanHtmlRemovesScriptBlocks(): void
    {
        $html = 'Hello <script>alert("xss")</script> World';

        self::assertSame('Hello World', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() strips inline <style> blocks and their CSS content
     * from the HTML string, leaving only the surrounding text joined by a space.
     */
    #[Test]
    public function cleanHtmlRemovesStyleBlocks(): void
    {
        $html = 'Hello <style>.foo { color: red; }</style> World';

        self::assertSame('Hello World', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() correctly removes multiline <script> and <style> blocks
     * including their full content spanning multiple lines, returning only the visible
     * text content with normalized whitespace.
     */
    #[Test]
    public function cleanHtmlRemovesMultilineScriptAndStyle(): void
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

        self::assertSame('Before After', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() inserts spaces between adjacent block-level elements
     * to prevent text from separate paragraphs being concatenated without a separator.
     */
    #[Test]
    public function cleanHtmlPreventsWordConcatenation(): void
    {
        $html = '<p>First</p><p>Second</p>';

        self::assertSame('First Second', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() converts non-breaking space HTML entities (&nbsp;)
     * into regular spaces and collapses consecutive non-breaking spaces into a
     * single space character.
     */
    #[Test]
    public function cleanHtmlConvertsNonBreakingSpaces(): void
    {
        $html = 'Word1&nbsp;Word2&nbsp;&nbsp;Word3';

        self::assertSame('Word1 Word2 Word3', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() removes all HTML tags including nested formatting tags
     * like <strong> and <em>, as well as structural tags like <div> and <h1>,
     * returning only the plain text content with proper spacing.
     */
    #[Test]
    public function cleanHtmlStripsAllHtmlTags(): void
    {
        $html = '<div class="wrapper"><h1>Title</h1><p>Content with <strong>bold</strong> and <em>italic</em></p></div>';

        self::assertSame('Title Content with bold and italic', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() decodes HTML entities such as &eacute;, &amp;,
     * &lt;, and &gt; into their corresponding UTF-8 characters after stripping tags.
     */
    #[Test]
    public function cleanHtmlDecodesHtmlEntities(): void
    {
        $html = 'Caf&eacute; &amp; Restaurant &lt;Gourmet&gt;';

        self::assertSame('Café & Restaurant <Gourmet>', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() normalizes all forms of whitespace (multiple spaces,
     * newlines, tabs, carriage returns) into single space characters, producing
     * a clean single-line string.
     */
    #[Test]
    public function cleanHtmlNormalizesWhitespace(): void
    {
        $html = "Word1   Word2\n\nWord3\t\tWord4\r\nWord5";

        self::assertSame('Word1 Word2 Word3 Word4 Word5', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() trims leading and trailing whitespace from the final
     * result, including whitespace that was outside or inside HTML tags at the
     * boundaries of the input string.
     */
    #[Test]
    public function cleanHtmlTrimsLeadingAndTrailingWhitespace(): void
    {
        $html = '   <p> Content </p>   ';

        self::assertSame('Content', ContentExtractor::cleanHtml($html));
    }

    /**
     * Tests that cleanHtml() returns an empty string when given an empty string
     * as input, confirming it handles the edge case without errors.
     */
    #[Test]
    public function cleanHtmlReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', ContentExtractor::cleanHtml(''));
    }

    /**
     * Tests that cleanHtml() correctly processes a full HTML document including
     * DOCTYPE, head section with script/style resources, and body with navigation,
     * main content, and footer. Verifies that visible text is preserved, &nbsp;
     * entities are converted, HTML entities are decoded, and all tags, scripts,
     * and styles are completely removed from the output.
     */
    #[Test]
    public function cleanHtmlHandlesComplexDocument(): void
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

        $result = ContentExtractor::cleanHtml($html);

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
     * Tests that cleanHtml() handles nested or overlapping script tags gracefully,
     * ensuring that the content between the outermost <script> opening and closing
     * tags is fully removed while preserving the text before and after the script block.
     */
    #[Test]
    public function cleanHtmlHandlesNestedScriptTags(): void
    {
        $html = 'Before<script>var s = "<script>nested</script>";</script>After';

        $result = ContentExtractor::cleanHtml($html);

        self::assertStringContainsString('Before', $result);
        self::assertStringContainsString('After', $result);
        self::assertStringNotContainsString('nested', $result);
    }

    /**
     * Tests that cleanHtml() returns plain text input unchanged when the string
     * contains no HTML tags, entities, or special characters that require processing.
     */
    #[Test]
    public function cleanHtmlHandlesPlainTextWithoutTags(): void
    {
        $plainText = 'This is plain text without any HTML tags.';

        self::assertSame($plainText, ContentExtractor::cleanHtml($plainText));
    }
}
