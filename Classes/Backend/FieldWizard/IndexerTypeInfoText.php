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
 * Class IndexerTypeInfoText.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexerTypeInfoText extends AbstractNode
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function render(): array
    {
        $languageService = $this->getLanguageService();

        $typeLabel = htmlspecialchars(
            $languageService->sL(
                'LLL:EXT:typo3_search_algolia/Resources/Private/Language/locallang.xlf:tx_typo3searchalgolia_domain_model_indexingservice.type.' .
                $this->data['parameterArray']['itemFormElValue'][0]
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
     * @return LanguageService
     */
    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
