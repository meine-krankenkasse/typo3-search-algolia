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
 * The indexing service domain model.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexingService extends AbstractEntity
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
     * @var bool
     */
    protected bool $includeContentElements;

    /**
     * @var string
     */
    protected string $contentElementTypes = '';

    /**
     * @var string
     */
    protected string $pagesDoktype = '';

    /**
     * @var string
     */
    protected string $pagesSingle = '';

    /**
     * @var string
     */
    protected string $pagesRecursive = '';

    /**
     * @var string
     */
    protected string $fileCollections = '';

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
     * @return IndexingService
     */
    public function setCrdate(DateTime $crdate): IndexingService
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
     * @return IndexingService
     */
    public function setTstamp(DateTime $tstamp): IndexingService
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
     * @return IndexingService
     */
    public function setHidden(bool $hidden): IndexingService
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
     * @return IndexingService
     */
    public function setDeleted(bool $deleted): IndexingService
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
     * @return IndexingService
     */
    public function setTitle(string $title): IndexingService
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
     * @return IndexingService
     */
    public function setDescription(string $description): IndexingService
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
     * @return IndexingService
     */
    public function setType(string $type): IndexingService
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
     * @return IndexingService
     */
    public function setSearchEngine(SearchEngine $searchEngine): IndexingService
    {
        $this->searchEngine = $searchEngine;

        return $this;
    }

    /**
     * @return bool
     */
    public function isIncludeContentElements(): bool
    {
        return $this->includeContentElements;
    }

    /**
     * @param bool $includeContentElements
     *
     * @return IndexingService
     */
    public function setIncludeContentElements(bool $includeContentElements): IndexingService
    {
        $this->includeContentElements = $includeContentElements;

        return $this;
    }

    /**
     * @return string
     */
    public function getContentElementTypes(): string
    {
        return $this->contentElementTypes;
    }

    /**
     * @param string $contentElementTypes
     *
     * @return IndexingService
     */
    public function setContentElementTypes(string $contentElementTypes): IndexingService
    {
        $this->contentElementTypes = $contentElementTypes;

        return $this;
    }

    /**
     * @return string
     */
    public function getPagesDoktype(): string
    {
        return $this->pagesDoktype;
    }

    /**
     * @param string $pagesDoktype
     *
     * @return IndexingService
     */
    public function setPagesDoktype(string $pagesDoktype): IndexingService
    {
        $this->pagesDoktype = $pagesDoktype;

        return $this;
    }

    /**
     * @return string
     */
    public function getPagesSingle(): string
    {
        return $this->pagesSingle;
    }

    /**
     * @param string $pagesSingle
     *
     * @return IndexingService
     */
    public function setPagesSingle(string $pagesSingle): IndexingService
    {
        $this->pagesSingle = $pagesSingle;

        return $this;
    }

    /**
     * @return string
     */
    public function getPagesRecursive(): string
    {
        return $this->pagesRecursive;
    }

    /**
     * @param string $pagesRecursive
     *
     * @return IndexingService
     */
    public function setPagesRecursive(string $pagesRecursive): IndexingService
    {
        $this->pagesRecursive = $pagesRecursive;

        return $this;
    }

    /**
     * @return string
     */
    public function getFileCollections(): string
    {
        return $this->fileCollections;
    }

    /**
     * @param string $fileCollections
     *
     * @return IndexingService
     */
    public function setFileCollections(string $fileCollections): IndexingService
    {
        $this->fileCollections = $fileCollections;

        return $this;
    }
}
