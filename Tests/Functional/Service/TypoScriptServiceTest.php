<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use RuntimeException;
use TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Functional tests for TypoScriptService.
 *
 * Resolves the service through the real DI container without a bound
 * PSR-7/Extbase request, exactly the situation the index queue worker
 * console command runs in. This is what actually reproduces the
 * NoServerRequestGivenException fallback (a unit test with a mocked
 * ConfigurationManagerInterface cannot exercise the real TypoScript
 * parsing that fallback performs).
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(TypoScriptService::class)]
final class TypoScriptServiceTest extends AbstractFunctionalTestCase
{
    /**
     * Tests that getFieldMappingByType() still returns the extension's own
     * shipped field mapping when no request is bound (CLI/scheduler context),
     * instead of silently degrading to an empty array.
     */
    #[Test]
    public function getFieldMappingByTypeResolvesShippedDefaultsWithoutBoundRequest(): void
    {
        $subject = $this->get(TypoScriptService::class);

        self::assertSame(
            [
                'title'       => 'title',
                'subtitle'    => 'subTitle',
                'nav_title'   => 'navTitle',
                'description' => 'description',
                'abstract'    => 'teaser',
                'author'      => 'author',
                'keywords'    => 'keywords',
            ],
            $subject->getFieldMappingByType('pages')
        );
    }

    /**
     * Tests that getAllowedFileExtensions() still returns the extension's own
     * shipped allow-list when no request is bound (CLI/scheduler context).
     */
    #[Test]
    public function getAllowedFileExtensionsResolvesShippedDefaultsWithoutBoundRequest(): void
    {
        $subject = $this->get(TypoScriptService::class);

        self::assertSame(['pdf'], $subject->getAllowedFileExtensions());
    }

    /**
     * Tests that the CLI fallback parse result is memoized on the service
     * instance instead of being re-read/re-parsed on every call. The
     * scheduler worker command calls getTypoScriptConfiguration() once per
     * queue item on a single long-lived TypoScriptService instance, so
     * without memoization the bundled setup.typoscript would be re-parsed
     * hundreds of times per run for an identical result.
     *
     * Uses a throwaway fixture file via the constructor's optional
     * $cliFallbackSetupPath override, never the extension's real, source-
     * checked-out setup.typoscript: that file is a symlinked composer
     * "source" install shared across sessions, and mutating it directly
     * (as an earlier version of this test did) risks leaving it corrupted
     * if the process is killed between the rename and its cleanup.
     *
     * Proven behaviourally (not via reflection into private state): the
     * fixture file is deleted between the two calls. If the fallback
     * re-parsed on the second call, it would throw (a missing file makes
     * file_get_contents() return false, which raises a RuntimeException,
     * see getTypoScriptConfigurationThrowsWhenTheFallbackSetupIsUnreadableOnFirstCall()
     * below). A passing second call therefore proves the result came from
     * the memoized cache, not a fresh parse.
     */
    #[Test]
    public function getTypoScriptConfigurationMemoizesTheCliFallbackParseResult(): void
    {
        $fixturePath = $this->createTemporaryTypoScriptFixture(
            'module.tx_typo3searchalgolia.indexer.pages.fields.title = title'
        );

        try {
            $subject = $this->createSubjectWithFallbackPath($fixturePath);

            $firstResult = $subject->getFieldMappingByType('pages');

            self::assertSame(['title' => 'title'], $firstResult);

            unlink($fixturePath);

            self::assertSame($firstResult, $subject->getFieldMappingByType('pages'));
        } finally {
            if (file_exists($fixturePath)) {
                unlink($fixturePath);
            }
        }
    }

    /**
     * Tests that getTypoScriptConfiguration() throws a RuntimeException, rather
     * than silently degrading to an empty configuration, when the CLI fallback's
     * setup file cannot be read on the very first call (before any memoization
     * has happened).
     *
     * #[WithoutErrorHandler] is required: PHPUnit's own error handler flags any
     * PHP-level warning as risky regardless of the @-suppression on the SUT's
     * file_get_contents() call (that suppression only works against PHP's
     * built-in handler, which is what this attribute falls back to).
     */
    #[Test]
    #[WithoutErrorHandler]
    public function getTypoScriptConfigurationThrowsWhenTheFallbackSetupIsUnreadableOnFirstCall(): void
    {
        $subject = $this->createSubjectWithFallbackPath(
            sys_get_temp_dir() . '/typo3-search-algolia-test-does-not-exist.typoscript'
        );

        $this->expectException(RuntimeException::class);

        $subject->getFieldMappingByType('pages');
    }

    /**
     * Creates a temporary TypoScript fixture file with the given content.
     */
    private function createTemporaryTypoScriptFixture(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'typo3-search-algolia-test-');

        self::assertNotFalse($path);

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Creates a TypoScriptService instance wired to the given fallback setup
     * path instead of the extension's real, shipped setup.typoscript.
     */
    private function createSubjectWithFallbackPath(string $setupPath): TypoScriptService
    {
        return new TypoScriptService(
            $this->get(ConfigurationManagerInterface::class),
            $this->get(TypoScriptStringFactory::class),
            $setupPath,
        );
    }
}
