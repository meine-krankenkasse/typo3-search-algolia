<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener\Lowlevel;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use TYPO3\CMS\Lowlevel\Event\ModifyBlindedConfigurationOptionsEvent;

use function array_replace_recursive;

/**
 * Blinds the Algolia API key in the backend module "Configuration".
 *
 * The module shows the configuration variables to every administrator and hides
 * only a fixed list of keys. The listener adds the API key of this extension to
 * that list. The event is dispatched only when typo3/cms-lowlevel is installed.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class BlindApiKeyEventListener
{
    /**
     * Replaces the API key of the extension in the blinded options of the configuration variables.
     *
     * @param ModifyBlindedConfigurationOptionsEvent $event The event that collects the blinded options
     *
     * @return void
     */
    public function __invoke(ModifyBlindedConfigurationOptionsEvent $event): void
    {
        if ($event->getProviderIdentifier() !== 'confVars') {
            return;
        }

        $event->setBlindedConfigurationOptions(
            array_replace_recursive(
                $event->getBlindedConfigurationOptions(),
                [
                    'TYPO3_CONF_VARS' => [
                        'EXTENSIONS' => [
                            Constants::EXTENSION_NAME => [
                                'apiKey' => '******',
                            ],
                        ],
                    ],
                ],
            ),
        );
    }
}
