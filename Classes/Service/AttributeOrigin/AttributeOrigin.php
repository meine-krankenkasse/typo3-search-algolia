<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin;

/**
 * The origin an attribute on an assembled document was classified as.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
enum AttributeOrigin: string
{
    /**
     * The attribute is one of DocumentBuilder's hardcoded standard fields
     * (uid, pid, type, indexed, created, changed).
     */
    case Default = 'default';

    /**
     * The attribute is mapped via TypoScript
     * (module.tx_typo3searchalgolia.indexer.<table>.fields.<field>).
     */
    case TypoScript = 'typoscript';

    /**
     * The attribute was added or overwritten by an AfterDocumentAssembledEvent
     * listener, collectively, regardless of which listener or package.
     */
    case Listener = 'listener';
}
