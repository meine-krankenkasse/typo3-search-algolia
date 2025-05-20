<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Backend\FieldWizard;

use Override;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Field wizard for displaying additional information about indexer types.
 *
 * This class implements a TYPO3 form node that renders descriptive text
 * for the selected indexer type in the backend interface. It enhances
 * the user experience by providing context-specific information about
 * the currently selected indexer type directly in the form.
 *
 * The wizard retrieves the appropriate label from the extension's language
 * files based on the currently selected indexer type and displays it
 * as formatted HTML below the selection field.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexerTypeInfoText extends AbstractNode
{
    /**
     * Renders the indexer type information text as HTML.
     *
     * This method generates the HTML output for the field wizard that displays
     * descriptive text about the selected indexer type. It:
     *
     * 1. Retrieves the language service for localization
     * 2. Fetches the appropriate label from the language files based on the selected type
     * 3. Wraps the label in HTML markup for proper display in the backend form
     *
     * The resulting HTML is returned as part of an array structure that TYPO3
     * expects from form node renderers.
     *
     * @return array<string, mixed> The render array containing the HTML output
     */
    #[Override]
    public function render(): array
    {
        $languageService = $this->getLanguageService();

        $typeLabel = htmlspecialchars(
            $languageService->sL(
                'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.type.' .
                ($this->data['parameterArray']['itemFormElValue'][0] ?? '')
            )
        );

        return [
            'html' => <<<HTML
<div class="my-2">
    {$typeLabel}
</div>
HTML,
        ];
    }

    /**
     * Returns the TYPO3 language service for localization.
     *
     * This helper method provides access to the global TYPO3 language service,
     * which is used for translating labels and messages in the backend interface.
     * The language service handles the retrieval of localized strings from
     * language files based on the current backend user's language preference.
     *
     * @return LanguageService The TYPO3 language service instance
     */
    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
