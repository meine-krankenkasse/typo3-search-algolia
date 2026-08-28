<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\Backend\FieldWizard;

use MeineKrankenkasse\Typo3SearchAlgolia\Backend\FieldWizard\IndexerTypeInfoText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Unit tests for IndexerTypeInfoText.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(IndexerTypeInfoText::class)]
final class IndexerTypeInfoTextTest extends TestCase
{
    /**
     * Tests that render() looks up the type label with the "LLL:" prefix
     * restored on the label reference. Without the prefix,
     * {@see LanguageService::sL()} treats a missing translation differently
     * (it falls back to returning the raw, untranslated reference string
     * instead of an empty string), so a selected type with no matching
     * translation would leak the raw label key into the backend HTML. This
     * is a regression test for that fix: it fails if the "LLL:" prefix is
     * dropped from the reference passed to sL().
     */
    #[Test]
    public function renderPrefixesLabelReferenceWithLll(): void
    {
        $fieldWizard = new IndexerTypeInfoText();
        $fieldWizard->setData([
            'parameterArray' => [
                'itemFormElValue' => ['pages'],
            ],
        ]);

        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->expects(self::once())
            ->method('sL')
            ->with(
                'LLL:typo3_search_algolia.messages:tx_typo3searchalgolia_domain_model_indexingservice.type.pages',
            )
            ->willReturn('Pages');

        $GLOBALS['LANG'] = $languageService;

        $result = $fieldWizard->render();

        self::assertStringContainsString('Pages', $result['html']);
    }

    /**
     * Tests that render() falls back to an empty selected-type segment when
     * no item value is present in the parameter array (e.g. a freshly added
     * record where the select field has not been populated yet), instead of
     * emitting a PHP warning for an undefined array key.
     */
    #[Test]
    public function renderHandlesMissingItemFormElValue(): void
    {
        $fieldWizard = new IndexerTypeInfoText();
        $fieldWizard->setData([
            'parameterArray' => [
                'itemFormElValue' => [],
            ],
        ]);

        $languageService = $this->createMock(LanguageService::class);
        $languageService
            ->expects(self::once())
            ->method('sL')
            ->with(
                'LLL:typo3_search_algolia.messages:tx_typo3searchalgolia_domain_model_indexingservice.type.',
            )
            ->willReturn('');

        $GLOBALS['LANG'] = $languageService;

        $result = $fieldWizard->render();

        self::assertStringNotContainsString('LLL:', $result['html']);
    }
}
