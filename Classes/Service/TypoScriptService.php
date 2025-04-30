<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

use function is_string;

/**
 * Class TypoScriptService.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class TypoScriptService
{
    /**
     * @var ConfigurationManagerInterface
     */
    private ConfigurationManagerInterface $configurationManager;

    /**
     * Constructor.
     *
     * @param ConfigurationManagerInterface $configurationManager
     */
    public function __construct(ConfigurationManagerInterface $configurationManager)
    {
        $this->configurationManager = $configurationManager;
    }

    /**
     * Returns the TypoScript configuration of the extension.
     *
     * @return array<string, array<string, array<string, string|array<string, string>>>>
     */
    public function getTypoScriptConfiguration(): array
    {
        $typoscriptConfiguration = $this->configurationManager
            ->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
                Constants::EXTENSION_NAME
            );

        return GeneralUtility::removeDotsFromTS($typoscriptConfiguration)['module']['tx_typo3searchalgolia'];
    }

    /**
     * Returns the configured file extensions.
     *
     * @return string[]
     */
    public function getAllowedFileExtensions(): array
    {
        $typoscriptConfiguration = $this->getTypoScriptConfiguration();

        if (
            !isset($typoscriptConfiguration['indexer'][FileIndexer::TABLE]['extensions'])
            || !is_string($typoscriptConfiguration['indexer'][FileIndexer::TABLE]['extensions'])
        ) {
            return [];
        }

        return GeneralUtility::trimExplode(
            ',',
            $typoscriptConfiguration['indexer'][FileIndexer::TABLE]['extensions'],
            true
        );
    }
}
