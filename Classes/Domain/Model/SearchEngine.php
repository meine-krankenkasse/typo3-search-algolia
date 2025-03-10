<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Domain\Model;

use DateTime;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * The search engine domain model.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class SearchEngine extends AbstractEntity
{
    /**
     * @var DateTime
     */
    protected DateTime $crdate;

    /**
     * @var DateTime
     */
    protected DateTime $tstamp;

    /**
     * @var bool
     */
    protected bool $deleted = false;

    /**
     * @var string
     */
    protected string $title = '';

    /**
     * @var string
     */
    protected string $description = '';

    /**
     * @var string
     */
    protected string $engine;

    /**
     * @var string
     */
    protected string $indexName;

    /**
     * @return DateTime
     */
    public function getCrdate(): DateTime
    {
        return $this->crdate;
    }

    /**
     * @param DateTime $crdate
     *
     * @return SearchEngine
     */
    public function setCrdate(DateTime $crdate): SearchEngine
    {
        $this->crdate = $crdate;

        return $this;
    }

    /**
     * @return DateTime
     */
    public function getTstamp(): DateTime
    {
        return $this->tstamp;
    }

    /**
     * @param DateTime $tstamp
     *
     * @return SearchEngine
     */
    public function setTstamp(DateTime $tstamp): SearchEngine
    {
        $this->tstamp = $tstamp;

        return $this;
    }

    /**
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * @param bool $deleted
     *
     * @return SearchEngine
     */
    public function setDeleted(bool $deleted): SearchEngine
    {
        $this->deleted = $deleted;

        return $this;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title
     *
     * @return SearchEngine
     */
    public function setTitle(string $title): SearchEngine
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $description
     *
     * @return SearchEngine
     */
    public function setDescription(string $description): SearchEngine
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string
     */
    public function getEngine(): string
    {
        return $this->engine;
    }

    /**
     * @param string $engine
     *
     * @return SearchEngine
     */
    public function setEngine(string $engine): SearchEngine
    {
        $this->engine = $engine;

        return $this;
    }

    /**
     * @return string
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * @param string $indexName
     *
     * @return SearchEngine
     */
    public function setIndexName(string $indexName): SearchEngine
    {
        $this->indexName = $indexName;

        return $this;
    }
}
