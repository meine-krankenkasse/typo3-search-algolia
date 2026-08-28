<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;

/**
 * Classifies every field on an already-assembled Document by its origin.
 *
 * Implementations MUST NOT run DocumentBuilder::assemble() themselves, they
 * only classify a document that was already assembled by the caller.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 *
 * @api
 */
interface AttributeOriginResolverInterface
{
    /**
     * Classifies every field on the given document by its origin.
     *
     * @param Document $document The already-assembled document to classify
     *
     * @return AttributeOriginMap The classification result
     */
    public function resolve(Document $document): AttributeOriginMap;
}
