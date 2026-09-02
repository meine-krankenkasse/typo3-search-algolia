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
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Base for a DocumentBuilder test double that delegates every call to a
 * real, container-resolved DocumentBuilder instance, overriding only
 * assemble() to inject one deliberate behaviour for one configured table.
 *
 * Shared by ThrowingForTableDocumentBuilder, ArrayFieldInjectingDocumentBuilder,
 * ObjectFieldInjectingDocumentBuilder, and StringFieldInjectingDocumentBuilder,
 * each of which differs only in what assemble() does for the table it's
 * scoped to.
 */
abstract class AbstractDelegatingDocumentBuilder extends DocumentBuilder
{
    protected ?IndexerInterface $indexerForTest = null;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        TypoScriptService $typoScriptService,
        protected readonly DocumentBuilder $realDocumentBuilder,
    ) {
        parent::__construct(
            $eventDispatcher,
            $typoScriptService,
        );
    }

    #[Override]
    public function setIndexer(?IndexerInterface $indexer): static
    {
        $this->indexerForTest = $indexer;
        $this->realDocumentBuilder->setIndexer($indexer);

        return $this;
    }

    #[Override]
    public function setRecord(array $record): static
    {
        $this->realDocumentBuilder->setRecord($record);

        return $this;
    }

    #[Override]
    public function setIndexingService(IndexingService $indexingService): static
    {
        $this->realDocumentBuilder->setIndexingService($indexingService);

        return $this;
    }

    #[Override]
    public function getDocument(): Document
    {
        return $this->realDocumentBuilder->getDocument();
    }
}
