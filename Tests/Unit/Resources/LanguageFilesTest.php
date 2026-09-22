<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SimpleXMLElement;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

use function array_diff;
use function array_filter;
use function file_get_contents;
use function preg_match_all;
use function simplexml_load_file;
use function sort;
use function str_starts_with;

/**
 * Unit tests for the language files shipped with the extension.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversNothing]
final class LanguageFilesTest extends UnitTestCase
{
    private const string LANGUAGE_DIRECTORY = __DIR__ . '/../../../Resources/Private/Language/';

    /**
     * Returns the ids of all trans-units of the given language file.
     *
     * @param string $file The file name inside the language directory
     *
     * @return string[]
     */
    private function getTranslationIds(string $file): array
    {
        $document = simplexml_load_file(self::LANGUAGE_DIRECTORY . $file);

        self::assertInstanceOf(SimpleXMLElement::class, $document);

        $units = $document->xpath('//trans-unit');

        self::assertIsArray($units);

        $ids = [];

        foreach ($units as $unit) {
            $ids[] = (string) $unit['id'];
        }

        sort($ids);

        return $ids;
    }

    /**
     * Provides the base language files that have a German counterpart.
     *
     * @return array<string, array{string}>
     */
    public static function baseLanguageFileProvider(): array
    {
        return [
            'locallang'            => ['locallang.xlf'],
            'locallang_mod'        => ['locallang_mod.xlf'],
            'locallang_mod_search' => ['locallang_mod_search.xlf'],
        ];
    }

    /**
     * Tests that a base language file and its German counterpart declare the same
     * trans-unit ids, so no label is missing in one language or misspelled.
     */
    #[Test]
    #[DataProvider('baseLanguageFileProvider')]
    public function germanLanguageFileDeclaresTheSameIdsAsTheBaseFile(string $baseFile): void
    {
        $baseIds   = $this->getTranslationIds($baseFile);
        $germanIds = $this->getTranslationIds('de.' . $baseFile);

        self::assertSame([], array_diff($baseIds, $germanIds), 'Ids missing in the German file');
        self::assertSame([], array_diff($germanIds, $baseIds), 'Ids missing in the base file');
    }

    /**
     * Tests that every label key the queue module partial translates from this
     * extension's language file exists in the base language file.
     */
    #[Test]
    public function queueModulePartialUsesDeclaredTranslationIds(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/QueueModule/IndexingServices.html');

        self::assertIsString($partial);

        $found = preg_match_all('/key(?:="|:\s*\')([^"\']+)/', $partial, $matches);

        self::assertGreaterThan(0, $found);

        // Keys with an explicit LLL: reference point to other language files
        $ownKeys = array_filter(
            $matches[1],
            static fn (string $key): bool => !str_starts_with($key, 'LLL:'),
        );

        self::assertNotSame([], $ownKeys);

        self::assertSame(
            [],
            array_diff($ownKeys, $this->getTranslationIds('locallang.xlf')),
            'Label keys used by the partial but not declared'
        );
    }
}
