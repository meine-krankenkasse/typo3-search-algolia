<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Builder;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\AfterDocumentAssembledEvent;
use MeineKrankenkasse\Typo3SearchAlgolia\Model\Document;
use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Class DocumentBuilder.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class DocumentBuilder
{
    /**
     * @var ConfigurationManager
     */
    private ConfigurationManager $configurationManager;

    /**
     * @var IndexerInterface|null
     */
    private ?IndexerInterface $indexer = null;

    /**
     * @var array<mixed>
     */
    private array $record = [];

    /**
     * @var Document
     */
    private Document $document;

    /**
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $eventDispatcher;

    /**
     * @param ConfigurationManager     $configurationManager
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        ConfigurationManager $configurationManager,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->configurationManager = $configurationManager;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param null|IndexerInterface $indexer
     *
     * @return DocumentBuilder
     */
    public function setIndexer(?IndexerInterface $indexer): DocumentBuilder
    {
        $this->indexer = $indexer;
        return $this;
    }

    /**
     * @param array $record
     *
     * @return DocumentBuilder
     */
    public function setRecord(array $record): DocumentBuilder
    {
        $this->record = $record;
        return $this;
    }

    /**
     * Returns the assembled document.
     *
     * @return Document
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Assembles the document.
     *
     * @return DocumentBuilder
     */
    public function assemble(): DocumentBuilder
    {
        $this->document = GeneralUtility::makeInstance(
            Document::class,
            $this->indexer,
            $this->record
        );

        $tableName = $this->indexer->getTable();

        // Set common fields
        $this->document
            ->setField('uid', $this->record['uid'])
            ->setField('pid', $this->record['pid'] ?? 0)
            ->setField('type', $tableName)
            ->setField('indexed', time());

        // Add created at timestamp
        if (
            isset($GLOBALS['TCA'][$tableName]['ctrl']['crdate'])
            && ($GLOBALS['TCA'][$tableName]['ctrl']['crdate'] !== '')
        ) {
            $this->document->setField(
                'created',
                $this->record[$GLOBALS['TCA'][$tableName]['ctrl']['crdate']]
            );
        }

        // Add changed at timestamp
        if (
            isset($GLOBALS['TCA'][$tableName]['ctrl']['tstamp'])
            && ($GLOBALS['TCA'][$tableName]['ctrl']['tstamp'] !== '')
        ) {
            $this->document->setField(
                'changed',
                $this->record[$GLOBALS['TCA'][$tableName]['ctrl']['tstamp']]
            );
        }

        // Fill the document with configured fields for the indexer type
        $this->addConfiguredFieldsToDocument();

        $this->eventDispatcher
            ->dispatch(
                new AfterDocumentAssembledEvent(
                    $this->document,
                    $this->indexer,
                    $this->record
                )
            );

        return $this;
    }

    /**
     * Assigns to the document all fields that were configured within TypoScript s
     * under "module.tx_typo3searchalgolia.index.<TYPE>".
     *
     * @return void
     */
    private function addConfiguredFieldsToDocument(): void
    {
        $indexerType             = $this->indexer->getType();
        $typoscriptConfiguration = $this->getTypoScriptConfiguration();

        foreach ($this->record as $recordFieldName => $recordValue) {
            if (!isset($typoscriptConfiguration['indexer'][$indexerType][$recordFieldName])) {
                continue;
            }

            $fieldName  = $typoscriptConfiguration['indexer'][$indexerType][$recordFieldName];
            $fieldValue = $recordValue;

            // Ignore empty field values
            if (($fieldValue === null)
                || ($fieldValue === '')
                || (($fieldValue === []))
            ) {
                continue;
            }

            $this->document
                ->setField(
                    $fieldName,
                    $fieldValue
                );
        }
    }

    /**
     * Returns the TypoScript configuration of the extension.
     *
     * @return array
     */
    private function getTypoScriptConfiguration(): array
    {
        $typoscriptConfiguration = $this->configurationManager
            ->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT,
                Constants::EXTENSION_NAME
            );

        return GeneralUtility::removeDotsFromTS($typoscriptConfiguration)['module']['tx_typo3searchalgolia'];
    }
}
