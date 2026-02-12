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
}
