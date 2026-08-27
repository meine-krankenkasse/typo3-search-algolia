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
use Override;
use TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Configuration\Exception\NoServerRequestGivenException;

use function file_get_contents;
use function is_array;
use function is_string;

/**
 * Service for accessing TypoScript configuration values.
 *
 * This service provides methods for retrieving and processing TypoScript
 * configuration values specific to the search extension. It handles:
 * - Accessing the extension's TypoScript configuration
 * - Retrieving specific configuration values like allowed file extensions
 * - Processing raw configuration values into usable formats
 *
 * The TypoScript configuration controls various aspects of the search
 * functionality, including which file types can be indexed and other
 * indexing-related settings.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class TypoScriptService implements TypoScriptServiceInterface
{
    /**
     * Constructor for the TypoScript service.
     *
     * Initializes the service with the TYPO3 configuration manager
     * for accessing TypoScript settings.
     *
     * @param ConfigurationManagerInterface $configurationManager    The TYPO3 configuration manager
     * @param TypoScriptStringFactory       $typoScriptStringFactory Factory used to parse the extension's own
     *                                                               bundled TypoScript when no request is bound
     */
    public function __construct(
        private ConfigurationManagerInterface $configurationManager,
        private TypoScriptStringFactory $typoScriptStringFactory,
    ) {
    }

    /**
     * Returns the complete TypoScript configuration of the extension.
     *
     * This method retrieves the full TypoScript configuration for the extension
     * and processes it into a usable array format by removing the TypoScript dots.
     * The configuration contains all settings defined in the extension's TypoScript.
     *
     * @return array<string, array<string, array<string, string|array<string, string>>>> The processed TypoScript configuration
     */
    #[Override]
    public function getTypoScriptConfiguration(): array
    {
        try {
            $typoscriptConfiguration = $this->configurationManager
                ->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
        } catch (NoServerRequestGivenException) {
            // No request is bound in CLI/scheduler context (e.g. the index queue worker
            // command), so site TypoScript cannot be resolved the normal request-bound way.
            // Parse the extension's own bundled setup.typoscript directly instead, which
            // covers the module.tx_typo3searchalgolia field mappings this service reads.
            // Note: a project-level TypoScript override of these settings does not apply
            // in this fallback, only the extension's shipped defaults are used here.
            $typoscriptConfiguration = $this->typoScriptStringFactory
                ->parseFromStringWithIncludes(
                    'typo3-search-algolia-cli-fallback',
                    (string) file_get_contents(
                        ExtensionManagementUtility::extPath(Constants::EXTENSION_NAME)
                        . 'Configuration/TypoScript/setup.typoscript'
                    )
                )
                ->toArray();
        }

        return GeneralUtility::removeDotsFromTS($typoscriptConfiguration)['module']['tx_typo3searchalgolia'] ?? [];
    }

    /**
     * Returns the field mapping for a specific indexer type.
     *
     * This method retrieves the field mapping for a specific indexer type
     * from the extension's TypoScript configuration. The field mapping
     * defines which fields should be indexed for a specific content type.
     *
     * The field mapping is an array of field names, where each field name
     * corresponds to a field in the content record. The field mapping is
     * used by the indexer to determine which fields should be indexed for
     * a specific content type.
     *
     * @param string $indexerType
     *
     * @return string[]
     */
    #[Override]
    public function getFieldMappingByType(string $indexerType): array
    {
        $typoscriptConfiguration = $this->getTypoScriptConfiguration();

        if (isset($typoscriptConfiguration['indexer'][$indexerType]['fields'])
            && is_array($typoscriptConfiguration['indexer'][$indexerType]['fields'])
        ) {
            return $typoscriptConfiguration['indexer'][$indexerType]['fields'];
        }

        return [];
    }

    /**
     * Returns the file extensions allowed for indexing.
     *
     * This method retrieves the list of file extensions that are configured
     * to be indexed by the file indexer. Only files with these extensions
     * will be considered for indexing, which helps filter out file types
     * that are not suitable for search (e.g., system files).
     *
     * @return string[] Array of allowed file extensions (e.g., ['pdf', 'doc', 'docx'])
     */
    #[Override]
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
