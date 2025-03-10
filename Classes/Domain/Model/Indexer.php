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
 * The indexer domain model.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class Indexer extends AbstractEntity
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
    protected bool $hidden = false;

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
    protected string $type;

    /**
     * @var SearchEngine
     */
    protected SearchEngine $searchEngine;

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
     * @return Indexer
     */
    public function setCrdate(DateTime $crdate): Indexer
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
     * @return Indexer
     */
    public function setTstamp(DateTime $tstamp): Indexer
    {
        $this->tstamp = $tstamp;

        return $this;
    }

    /**
     * @return bool
     */
    public function isHidden(): bool
    {
        return $this->hidden;
    }

    /**
     * @param bool $hidden
     *
     * @return Indexer
     */
    public function setHidden(bool $hidden): Indexer
    {
        $this->hidden = $hidden;

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
     * @return Indexer
     */
    public function setDeleted(bool $deleted): Indexer
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
     * @return Indexer
     */
    public function setTitle(string $title): Indexer
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
     * @return Indexer
     */
    public function setDescription(string $description): Indexer
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     *
     * @return Indexer
     */
    public function setType(string $type): Indexer
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return SearchEngine
     */
    public function getSearchEngine(): SearchEngine
    {
        return $this->searchEngine;
    }

    /**
     * @param SearchEngine $searchEngine
     *
     * @return Indexer
     */
    public function setSearchEngine(SearchEngine $searchEngine): Indexer
    {
        $this->searchEngine = $searchEngine;

        return $this;
    }
}
