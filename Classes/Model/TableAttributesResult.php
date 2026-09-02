<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Model;

/**
 * One table's contribution to the Attribute Overview module's aggregation,
 * see AttributeOverviewModuleController::buildTableAttributes().
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
final readonly class TableAttributesResult
{
    /**
     * @param string                                                    $status        One of AttributeOverviewModuleController::STATUS_* except STATUS_ERROR, which is caught, not returned
     * @param array<string, array{origin: string, detail: string|null}> $originDetails Empty unless $status is STATUS_OK, see AttributeOriginMap::getOriginDetails()
     * @param array<string, mixed>                                      $fields        Empty unless $status is STATUS_OK, see Document::getFields()
     */
    public function __construct(
        public string $status,
        public array $originDetails,
        public array $fields,
    ) {
    }
}
