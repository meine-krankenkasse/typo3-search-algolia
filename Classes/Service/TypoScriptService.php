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
use MeineKrankenkasse\Typo3SearchAlgolia\Exception\CliFallbackTypoScriptUnreadableException;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\Indexer\FileIndexer;
use Override;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Configuration\Exception\NoServerRequestGivenException;

use function file_get_contents;
use function is_array;
use function is_string;
use function restore_error_handler;
use function set_error_handler;

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
final class TypoScriptService implements TypoScriptServiceInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Memoized result of the CLI fallback parse (the extension's bundled
     * setup.typoscript, read and parsed directly, see getCliFallbackTypoScript()).
     * The scheduler worker command (mkk:queue:index:worker) runs as a single
     * long-lived process and calls getTypoScriptConfiguration() once per queue
     * item, so without this memoization the same static file would be re-read,
     * re-hashed and re-parsed hundreds of times per run for an identical result.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cliFallbackTypoScript = null;

    /**
     * Constructor for the TypoScript service.
     *
     * Initializes the service with the TYPO3 configuration manager
     * for accessing TypoScript settings.
     *
     * @param ConfigurationManagerInterface $configurationManager    The TYPO3 configuration manager
     * @param TypoScriptStringFactory       $typoScriptStringFactory Factory used to parse the extension's own
     *                                                               bundled TypoScript when no request is bound
     * @param string|null                   $cliFallbackSetupPath    Overrides the path to the bundled
     *                                                               setup.typoscript used by the CLI fallback.
     *                                                               Not wired in Services.yaml, exists only so
     *                                                               tests can point this at a throwaway fixture
     *                                                               instead of mutating the extension's real,
     *                                                               source-checked-out setup.typoscript file.
     */
    public function __construct(
        private readonly ConfigurationManagerInterface $configurationManager,
        private readonly TypoScriptStringFactory $typoScriptStringFactory,
        private readonly ?string $cliFallbackSetupPath = null,
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
            $typoscriptConfiguration = $this->getCliFallbackTypoScript();
        }

        return GeneralUtility::removeDotsFromTS($typoscriptConfiguration)['module']['tx_typo3searchalgolia'] ?? [];
    }

    /**
     * Returns the extension's own bundled TypoScript setup, parsed directly
     * from disk, for use when no request is bound (CLI/scheduler context,
     * e.g. the index queue worker command) and the normal request-bound
     * ConfigurationManager cannot resolve site TypoScript.
     *
     * Known, durable limitations of this fallback:
     * - A project-level TypoScript override of these settings does not apply
     *   here. parseFromStringWithIncludes() parses only this one file, not the
     *   site's full sys_template cascade the request-bound path would resolve.
     * - [condition] blocks inside the parsed string are not evaluated.
     *   parseFromStringWithIncludes() wires a plain IncludeTreeTraverser
     *   without condition matching, so any [condition] added to
     *   setup.typoscript in the future would always be treated as true here,
     *   regardless of its actual outcome.
     *
     * @return array<string, mixed>
     */
    private function getCliFallbackTypoScript(): array
    {
        if ($this->cliFallbackTypoScript !== null) {
            return $this->cliFallbackTypoScript;
        }

        $this->logger?->warning(
            'Resolving TypoScript configuration via the CLI fallback (the bundled '
            . 'setup.typoscript only); project-level TypoScript overrides and any '
            . '[condition] blocks are not honored in this context.',
        );

        $setupPath = $this->cliFallbackSetupPath ?? (
            ExtensionManagementUtility::extPath(Constants::EXTENSION_NAME)
            . 'Configuration/TypoScript/setup.typoscript'
        );

        // The read failure is deliberately checked and reported via the exception
        // below, so PHP's own low-level warning for it is suppressed here (via a
        // scoped error handler, not the `@` operator) to avoid reporting the same
        // single failure twice.
        set_error_handler(static fn (): bool => true);

        try {
            $rawTypoScript = file_get_contents($setupPath);
        } finally {
            restore_error_handler();
        }

        if ($rawTypoScript === false) {
            throw new CliFallbackTypoScriptUnreadableException(
                'Unable to read the bundled TypoScript setup at "' . $setupPath . '".',
            );
        }

        return $this->cliFallbackTypoScript = $this->typoScriptStringFactory
            ->parseFromStringWithIncludes(
                'typo3-search-algolia-cli-fallback',
                $rawTypoScript,
            )
            ->toArray();
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
            true,
        );
    }
}
