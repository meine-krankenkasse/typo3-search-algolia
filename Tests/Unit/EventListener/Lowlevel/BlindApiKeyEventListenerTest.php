<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Unit\EventListener\Lowlevel;

use MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Lowlevel\BlindApiKeyEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Lowlevel\Event\ModifyBlindedConfigurationOptionsEvent;

/**
 * Unit tests for BlindApiKeyEventListener.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
#[CoversClass(BlindApiKeyEventListener::class)]
final class BlindApiKeyEventListenerTest extends TestCase
{
    /**
     * Tests that the API key of the extension is blinded for the provider of the
     * configuration variables, and that the options other listeners have blinded
     * are kept.
     */
    #[Test]
    public function blindsTheApiKeyOfTheConfigurationVariables(): void
    {
        $event = new ModifyBlindedConfigurationOptionsEvent(
            ['TYPO3_CONF_VARS' => ['DB' => ['password' => '******']]],
            'confVars',
        );

        (new BlindApiKeyEventListener())($event);

        self::assertSame(
            [
                'TYPO3_CONF_VARS' => [
                    'DB'         => ['password' => '******'],
                    'EXTENSIONS' => ['typo3_search_algolia' => ['apiKey' => '******']],
                ],
            ],
            $event->getBlindedConfigurationOptions(),
        );
    }

    /**
     * Tests that the options of any other configuration provider stay as they are.
     */
    #[Test]
    public function leavesTheOptionsOfOtherProvidersUntouched(): void
    {
        $event = new ModifyBlindedConfigurationOptionsEvent(
            ['TCA' => ['pages' => '******']],
            'tca',
        );

        (new BlindApiKeyEventListener())($event);

        self::assertSame(['TCA' => ['pages' => '******']], $event->getBlindedConfigurationOptions());
    }
}
