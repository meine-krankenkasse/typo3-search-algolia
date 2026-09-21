<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Unit tests for TypoScriptService.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(TypoScriptService::class)]
class TypoScriptServiceTest extends TestCase
{
    /**
     * Creates a TypoScriptService instance with a mocked ConfigurationManager
     * that returns the given TypoScript configuration.
     *
     * @param array<string, mixed> $typoScriptConfig The raw TypoScript config (with dots)
     */
    private function createSubjectWithConfig(array $typoScriptConfig): TypoScriptService
    {
        $configurationManagerMock = $this->createMock(ConfigurationManagerInterface::class);
        $configurationManagerMock
            ->method('getConfiguration')
            ->with(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT)
            ->willReturn($typoScriptConfig);

        return new TypoScriptService($configurationManagerMock);
    }

    /**
     * Tests that getFieldMappingByType() returns the configured field mapping
     * array for a known indexer type.
     */
    #[Test]
    public function getFieldMappingByTypeReturnsFieldsArray(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [
                        'pages.' => [
                            'fields.' => [
                                'title'       => 'title',
                                'description' => 'abstract',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $subject->getFieldMappingByType('pages');

        self::assertSame(['title' => 'title', 'description' => 'abstract'], $result);
    }

    /**
     * Tests that getFieldMappingByType() returns an empty array when
     * the requested indexer type does not exist in the configuration.
     */
    #[Test]
    public function getFieldMappingByTypeReturnsEmptyForUnknownType(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [
                        'pages.' => [
                            'fields.' => [
                                'title' => 'title',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $subject->getFieldMappingByType('non_existent_type');

        self::assertSame([], $result);
    }

    /**
     * Tests that getFieldMappingByType() returns an empty array when
     * the fields configuration value is not an array.
     */
    #[Test]
    public function getFieldMappingByTypeReturnsEmptyWhenFieldsNotArray(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [
                        'pages.' => [
                            'fields' => 'not_an_array',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $subject->getFieldMappingByType('pages');

        self::assertSame([], $result);
    }

    /**
     * Tests that getAllowedFileExtensions() returns the configured file
     * extensions as an array of strings.
     */
    #[Test]
    public function getAllowedFileExtensionsReturnsExtensions(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [
                        'sys_file_metadata.' => [
                            'extensions' => 'pdf,doc,docx',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $subject->getAllowedFileExtensions();

        self::assertSame(['pdf', 'doc', 'docx'], $result);
    }

    /**
     * Tests that getAllowedFileExtensions() returns an empty array when
     * no file extensions are configured in TypoScript.
     */
    #[Test]
    public function getAllowedFileExtensionsReturnsEmptyWhenNotConfigured(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [],
                ],
            ],
        ]);

        $result = $subject->getAllowedFileExtensions();

        self::assertSame([], $result);
    }

    /**
     * Tests that getExcludedColPos() returns the configured colPos values as
     * integers, tolerating whitespace around the entries and keeping the
     * value 0 (TYPO3's default main-content colPos) and negative values.
     */
    #[Test]
    public function getExcludedColPosReturnsIntegers(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [
                        'pages.' => [
                            'excludeColPos' => '0, 9999 ,101,-2',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([0, 9999, 101, -2], $subject->getExcludedColPos('pages'));
    }

    /**
     * Tests that getExcludedColPos() drops empty and non-numeric entries
     * instead of casting them to colPos 0, so a typo such as "9999x" can
     * never silently exclude the main-content column.
     */
    #[Test]
    public function getExcludedColPosIgnoresInvalidEntries(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [
                        'pages.' => [
                            'excludeColPos' => '9999x,abc,,1.5,5',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([5], $subject->getExcludedColPos('pages'));
    }

    /**
     * Tests that getExcludedColPos() returns an empty array when the option
     * is empty, missing or not a string, and that it reads the option of the
     * requested indexer type only.
     */
    #[Test]
    public function getExcludedColPosReturnsEmptyWhenNotConfigured(): void
    {
        $subject = $this->createSubjectWithConfig([
            'module.' => [
                'tx_typo3searchalgolia.' => [
                    'indexer.' => [
                        'pages.' => [
                            'excludeColPos' => '',
                        ],
                        'tt_content.' => [
                            'excludeColPos' => '9999',
                        ],
                        'news.' => [
                            'excludeColPos.' => ['9999'],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame([], $subject->getExcludedColPos('pages'));
        self::assertSame([9999], $subject->getExcludedColPos('tt_content'));
        self::assertSame([], $subject->getExcludedColPos('news'));
        self::assertSame([], $subject->getExcludedColPos('sys_file_metadata'));
    }
}
