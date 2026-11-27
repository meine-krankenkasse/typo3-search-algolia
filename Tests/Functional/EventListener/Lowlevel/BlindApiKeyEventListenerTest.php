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
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Lowlevel\Event\ModifyBlindedConfigurationOptionsEvent;

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
     * Tests that the dispatched event reaches the listener through the service
     * configuration and comes back with the API key blinded.
     */
    #[Test]
    public function theDispatchedEventBlindsTheApiKey(): void
    {
        $event = $this->get(EventDispatcherInterface::class)
            ->dispatch(new ModifyBlindedConfigurationOptionsEvent([], 'confVars'));

        self::assertInstanceOf(ModifyBlindedConfigurationOptionsEvent::class, $event);
        self::assertSame(
            ['TYPO3_CONF_VARS' => ['EXTENSIONS' => ['typo3_search_algolia' => ['apiKey' => '******']]]],
            $event->getBlindedConfigurationOptions(),
        );
    }
}
