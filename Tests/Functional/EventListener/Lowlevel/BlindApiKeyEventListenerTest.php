<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\EventListener\Lowlevel;

use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Lowlevel\BlindApiKeyEventListener;
use MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\ProviderRegistry;

/**
 * Functional test for the registration of BlindApiKeyEventListener in the real
 * container, with the lowlevel extension loaded.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(BlindApiKeyEventListener::class)]
final class BlindApiKeyEventListenerTest extends AbstractFunctionalTestCase
{
    /**
     * Core extensions required for the test.
     *
     * @var array<non-empty-string>
     */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'lowlevel',
    ];

    /**
     * Tests that the provider of the configuration variables, which the module
     * reads, returns the API key blinded and the application ID as configured.
     */
    #[Test]
    public function theConfigurationProviderBlindsTheApiKey(): void
    {
        // The registry builds every provider, among them one whose toolbar items
        // need a backend user.
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/be_users.csv');
        $this->setUpBackendUser(1);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_search_algolia'] = [
            'apiKey' => 'secret-api-key',
            'appId'  => 'application-id',
        ];

        $configuration = $this->get(ProviderRegistry::class)
            ->getProvider('confVars')
            ->getConfiguration();

        self::assertSame(
            [
                'apiKey' => '******',
                'appId'  => 'application-id',
            ],
            ArrayUtility::getValueByPath($configuration, 'EXTENSIONS/typo3_search_algolia'),
        );
    }
}
