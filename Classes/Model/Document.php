<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Model;

use MeineKrankenkasse\Typo3SearchAlgolia\Service\IndexerInterface;

/**
 * Class Document.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class Document
{
    /**
     * @var IndexerInterface
     */
    private IndexerInterface $indexer;

    /**
     * @var array<mixed>
     */
    private array $record;

    /**
     * @var array<int, mixed>
     */
    private array $fields = [];

    /**
     * Constructor.
     *
     * @param IndexerInterface $indexer
     * @param mixed[]          $record
     */
    public function __construct(IndexerInterface $indexer, array $record)
    {
        $this->indexer = $indexer;
        $this->record  = $record;
    }

    /**
     * @return IndexerInterface
     */
    public function getIndexer(): IndexerInterface
    {
        return $this->indexer;
    }

    /**
     * @return array
     */
    public function getRecord(): array
    {
        return $this->record;
    }

    /**
     * @return array<int, mixed>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Sets a field value.
     *
     * @param string $name  The field name
     * @param mixed  $value The value, if NULL, the field will be removed
     *
     * @return Document
     */
    public function setField(string $name, mixed $value): Document
    {
        if ($value === null) {
            $this->removeField($name);
        } else {
            $this->fields[$name] = $value;
        }

        return $this;
    }

    /**
     * Removes a field.
     *
     * @param string $name The field name
     *
     * @return Document
     */
    public function removeField(string $name): Document
    {
        if (isset($this->fields[$name])) {
            unset($this->fields[$name]);
        }

        return $this;
    }
}
