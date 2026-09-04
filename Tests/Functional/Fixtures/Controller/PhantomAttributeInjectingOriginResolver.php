<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOrigin;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginMap;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\AttributeOrigin\AttributeOriginResolverInterface;
use Override;

/**
 * Test double for AttributeOriginResolverInterface that delegates every
 * classification to a real, container-resolved resolver instance, except
 * that it additionally assigns one attribute name NOT present on the
 * assembled document's own field set for exactly one configured table.
 *
 * Used to reach AttributeOverviewModuleController::formatExampleValue()'s
 * "!array_key_exists($attributeName, $fields)" guard through the real
 * public flow (indexAction()): AttributeOriginResolverInterface is a
 * documented public-API extension point, so nothing in its contract guarantees
 * a returned AttributeOriginMap's keys are a subset of
 * Document::getFields()'s keys, that invariant is only upheld by the one
 * shipped implementation (PredictAndDiffAttributeOriginResolver), not by
 * the interface itself. This fixture simulates exactly the third-party
 * implementation the guard's own docblock names as the reason it exists.
 *
 * Mirrors NullReturningIndexerFactory's established delegate-to-a-real-
 * instance, override-one-table pattern.
 */
final readonly class PhantomAttributeInjectingOriginResolver implements AttributeOriginResolverInterface
{
    public function __construct(
        private AttributeOriginResolverInterface $realResolver,
        private string $tableToInjectFor,
        private string $phantomAttributeName,
    ) {
    }

    #[Override]
    public function resolve(Document $document): AttributeOriginMap
    {
        $map = $this->realResolver->resolve($document);

        if ($document->getIndexer()->getTable() === $this->tableToInjectFor) {
            return $map->withAttribute(
                $this->phantomAttributeName,
                AttributeOrigin::Listener,
            );
        }

        return $map;
    }
}
