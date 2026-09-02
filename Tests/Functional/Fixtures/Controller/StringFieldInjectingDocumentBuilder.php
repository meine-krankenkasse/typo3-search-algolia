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
 * additional string field onto the resulting Document for exactly one
 * configured table. Generic enough to be chained (one instance per table,
 * each wrapping the next as its own realDocumentBuilder) when a test needs
 * to control more than one table's contribution for the same attribute
 * name.
 *
 * Used by two tests for two different purposes: proving the flat overview
 * table's exampleValue column relies on Fluid's default auto-escaping
 * rather than pre-escaping the value itself (see
 * AttributeOverviewModuleController::formatExampleValue()'s own docblock),
 * by injecting a value containing HTML-significant characters and
 * asserting the rendered markup carries the HTML-entity-encoded form, not
 * raw markup; and proving mergeTableAttributes()'s "first NON-EMPTY value
 * wins" exampleValue rule, by chaining two instances (one per table) that
 * inject an empty value for the first table and a real one for a later
 * table under the same attribute name. The class's own docblock documents
 * that a third-party AfterDocumentAssembledEvent listener can set a field
 * to an arbitrary string, so a listener setting HTML-significant content
 * (e.g. an editor's page title containing an ampersand or a literal angle
 * bracket) is a plausible, real-world input this guard must handle, not an
 * absurd one.
 */
final class StringFieldInjectingDocumentBuilder extends AbstractDelegatingDocumentBuilder
{
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        TypoScriptService $typoScriptService,
        DocumentBuilder $realDocumentBuilder,
        private readonly string $tableToInjectFor,
        private readonly string $fieldName,
        private readonly string $fieldValue,
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
