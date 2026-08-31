<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Tests\Functional\Fixtures\Controller;

use Error;
use MeineKrankenkasse\Typo3SearchAlgolia\Builder\DocumentBuilder;
use MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model\IndexingService;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\TypoScriptService;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Test double for DocumentBuilder that throws during assemble() for exactly
 * one configured table, delegating every call to a real, container-resolved
 * DocumentBuilder instance for every other table.
 *
 * Used to prove AttributeOverviewModuleController::indexAction()'s per-table
 * try/catch (widened to catch Throwable) actually isolates a failing
 * table's section build from every other, successfully built table's
 * section - the module's own docblock explains that a third-party
 * AfterDocumentAssembledEvent listener throwing during assemble() is
 * exactly the real-world scenario this guards against.
 *
 * Throws \Error, deliberately NOT an \Exception subtype: \Error and
 * \Exception are separate branches under \Throwable, so a catch(Exception)
 * (the pre-fix state) does not catch this at all, while a catch(Throwable)
 * (the fix) does. This is the whole point of Fix 2, a misbehaving or
 * version-mismatched third-party listener is just as likely to throw a
 * \TypeError or \ArgumentCountError (both \Error subtypes) as an
 * \Exception, and a test using \RuntimeException here would pass
 * identically whether the catch clause were widened or not.
 */
final class ThrowingForTableDocumentBuilder extends DocumentBuilder
{
    private ?IndexerInterface $indexerForTest = null;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        TypoScriptService $typoScriptService,
        private readonly DocumentBuilder $realDocumentBuilder,
        private readonly string $tableToFailFor,
    ) {
        parent::__construct($eventDispatcher, $typoScriptService);
    }

    #[Override]
    public function setIndexer(?IndexerInterface $indexer): DocumentBuilder
    {
        $this->indexerForTest = $indexer;
        $this->realDocumentBuilder->setIndexer($indexer);

        return $this;
    }

    #[Override]
    public function setRecord(array $record): DocumentBuilder
    {
        $this->realDocumentBuilder->setRecord($record);

        return $this;
    }

    #[Override]
    public function setIndexingService(IndexingService $indexingService): DocumentBuilder
    {
        $this->realDocumentBuilder->setIndexingService($indexingService);

        return $this;
    }

    #[Override]
    public function assemble(): DocumentBuilder
    {
        if ($this->indexerForTest?->getTable() === $this->tableToFailFor) {
            throw new Error(
                'Simulated document assembly failure for table "' . $this->tableToFailFor . '".',
            );
        }

        $this->realDocumentBuilder->assemble();

        return $this;
    }

    #[Override]
    public function getDocument(): Document
    {
        return $this->realDocumentBuilder->getDocument();
    }
}
