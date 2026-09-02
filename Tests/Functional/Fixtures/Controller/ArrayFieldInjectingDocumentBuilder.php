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
 * additional field onto the resulting Document for exactly one configured
 * table.
 *
 * Used to reach AttributeOverviewModuleController::formatExampleValue()'s
 * non-scalar array-item fallback (`is_scalar($item) ? (string) $item : ''`)
 * through the real public flow (indexAction()), without inventing a new
 * production seam. The class's own docblock documents that a third-party
 * AfterDocumentAssembledEvent listener can call Document::setField(string
 * $name, mixed $value) with an arbitrary value, so a listener setting an
 * array field that itself contains a non-scalar item (e.g. a listener bug,
 * or a nested structure a developer forgot to flatten) is a plausible,
 * real-world input this fallback must handle, not an absurd one. This
 * fixture simulates exactly such a listener by injecting the field directly
 * onto the already-assembled Document, the same object real listeners
 * mutate via the real AfterDocumentAssembledEvent.
 */
final class ArrayFieldInjectingDocumentBuilder extends AbstractDelegatingDocumentBuilder
{
    /**
     * @param array<int|string, mixed> $fieldValue The array value to inject, containing at least one non-scalar item
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        TypoScriptService $typoScriptService,
        DocumentBuilder $realDocumentBuilder,
        private readonly string $tableToInjectFor,
        private readonly string $fieldName,
        private readonly array $fieldValue,
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
