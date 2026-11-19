<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Configuration;

use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory;

use function file_get_contents;

/**
 * Functional tests for the TypoScript shipped with the extension.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversNothing]
final class SetupTypoScriptTest extends AbstractFunctionalTestCase
{
    /**
     * Tests that the shipped setup.typoscript does not assign the excludeColPos
     * option. The extension's static template is usually included after the
     * site package, so an assignment here, even an empty one, would override the
     * value a site package sets and silently disable the exclusion.
     */
    #[Test]
    public function shippedSetupDoesNotAssignExcludeColPos(): void
    {
        $typoScript = file_get_contents(__DIR__ . '/../../../Configuration/TypoScript/setup.typoscript');

        self::assertIsString($typoScript);

        $setup = $this->get(TypoScriptStringFactory::class)
            ->parseFromStringWithIncludes('search-algolia-setup', $typoScript)
            ->toArray();

        $pagesIndexer = $setup['module.']['tx_typo3searchalgolia.']['indexer.']['pages.'] ?? null;

        // The parsed setup must actually contain the page indexer section, otherwise
        // the assertion below would pass on an empty result.
        self::assertIsArray($pagesIndexer);
        self::assertArrayHasKey('fields.', $pagesIndexer);
        self::assertArrayNotHasKey('excludeColPos', $pagesIndexer);
    }
}
