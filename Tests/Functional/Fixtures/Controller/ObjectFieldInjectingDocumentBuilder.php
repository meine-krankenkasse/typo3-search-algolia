<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Test double for DocumentBuilder that, after delegating the real assembly
 * to a real, container-resolved DocumentBuilder instance, injects one
 * additional field holding an object onto the resulting Document for
 * exactly one configured table.
 *
 * Used to reach AttributeOverviewModuleController::formatExampleValue()'s
 * outer "neither array nor scalar" fallback (the final "else" branch, as
 * opposed to the inner non-scalar-array-item fallback
 * ArrayFieldInjectingDocumentBuilder already covers) through the real
 * public flow (indexAction()). Document::setField(string $name, mixed
 * $value) accepts any value, and the class's own docblock documents that a
 * third-party AfterDocumentAssembledEvent listener can set one of arbitrary
 * shape, so a listener setting an object value (e.g. a DateTime it forgot
 * to format) is a plausible, real-world input this fallback must handle,
 * not an absurd one.
 */
final class ObjectFieldInjectingDocumentBuilder extends AbstractDelegatingDocumentBuilder
{
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        TypoScriptService $typoScriptService,
        DocumentBuilder $realDocumentBuilder,
        private readonly string $tableToInjectFor,
        private readonly string $fieldName,
        private readonly object $fieldValue,
    ) {
        parent::__construct(
            $eventDispatcher,
            $typoScriptService,
            $realDocumentBuilder,
        );
    }

    #[Override]
    public function assemble(): static
    {
        $this->realDocumentBuilder->assemble();

        if ($this->indexerForTest?->getTable() === $this->tableToInjectFor) {
            $this->realDocumentBuilder
                ->getDocument()
                ->setField(
                    $this->fieldName,
                    $this->fieldValue,
                );
        }

        return $this;
    }
}
