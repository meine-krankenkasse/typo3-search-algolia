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
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Configuration\Exception\NoServerRequestGivenException;

/**
 * Functional tests for TypoScriptService.
 *
 * Resolves the service through the real DI container without a bound
 * PSR-7/Extbase request, exactly the situation the index queue worker
 * console command runs in. A unit test with a mocked
 * ConfigurationManagerInterface cannot exercise the real TypoScript
 * resolution of the websites that this situation needs.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(TypoScriptService::class)]
final class TypoScriptServiceTest extends AbstractFunctionalTestCase
{
    /**
     * Tests that getFieldMappingByType() returns the extension's own
     * shipped field mapping when no request is bound (CLI/scheduler context)
     * and the installation has no website, instead of degrading to an empty
     * array.
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
            $subject->getFieldMappingByType('pages'),
        );
    }

    /**
     * Tests that getAllowedFileExtensions() returns the extension's own
     * shipped allow-list when no request is bound (CLI/scheduler context)
     * and the installation has no website.
     */
    #[Test]
    public function getAllowedFileExtensionsResolvesShippedDefaultsWithoutBoundRequest(): void
    {
        $subject = $this->get(TypoScriptService::class);

        self::assertSame(['pdf'], $subject->getAllowedFileExtensions());
    }

    /**
     * Tests that the excludeColPos option of the first website applies when no
     * request is bound, and that the conditions of its TypoScript are evaluated
     * for that website.
     */
    #[Test]
    public function getExcludedColPosReadsTheTypoScriptOfTheFirstWebsiteWithoutBoundRequest(): void
    {
        $this->createTwoWebsites();

        self::assertSame(
            [7],
            $this->get(TypoScriptService::class)->getExcludedColPos('pages'),
        );
    }

    /**
     * Tests that the field mapping of the first website is merged over the
     * shipped defaults when no request is bound.
     */
    #[Test]
    public function getFieldMappingByTypeAppliesTheFieldMappingOfTheFirstWebsiteWithoutBoundRequest(): void
    {
        $this->createTwoWebsites();

        self::assertSame(
            [
                'title'       => 'subTitle',
                'subtitle'    => 'subTitle',
                'nav_title'   => 'navTitle',
                'description' => 'description',
                'abstract'    => 'teaser',
                'author'      => 'author',
                'keywords'    => 'keywords',
            ],
            $this->get(TypoScriptService::class)->getFieldMappingByType('pages'),
        );
    }

    /**
     * Tests that the allowed file extensions of the first website apply when
     * no request is bound.
     */
    #[Test]
    public function getAllowedFileExtensionsAppliesTheSettingOfTheFirstWebsiteWithoutBoundRequest(): void
    {
        $this->createTwoWebsites();

        self::assertSame(
            ['pdf', 'docx'],
            $this->get(TypoScriptService::class)->getAllowedFileExtensions(),
        );
    }

    /**
     * Tests that resolving the TypoScript without a bound request neither
     * leaves a request in the global state nor binds one to the shared
     * configuration manager, because a bound request would switch every later
     * Extbase configuration lookup of the process to backend mode.
     */
    #[Test]
    public function resolvingTheTypoScriptLeavesNoRequestBehind(): void
    {
        $this->createTwoWebsites();

        $requestBefore = $GLOBALS['TYPO3_REQUEST'] ?? null;

        $this->get(TypoScriptService::class)->getExcludedColPos('pages');

        self::assertSame($requestBefore, $GLOBALS['TYPO3_REQUEST'] ?? null);

        $this->expectException(NoServerRequestGivenException::class);

        $this->get(ConfigurationManagerInterface::class)
            ->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
    }

    /**
     * Creates two websites with their root pages and their own TypoScript
     * templates, the first one sorted before the second one.
     */
    private function createTwoWebsites(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/pages_site_typoscript.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/sys_template_site_typoscript.csv');

        $siteWriter = $this->get(SiteWriter::class);

        $siteWriter->createNewBasicSite('first', 1, 'https://first.example.com/');
        $siteWriter->createNewBasicSite('second', 40, 'https://second.example.com/');
    }
}
