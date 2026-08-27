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
}
