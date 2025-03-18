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
 * The queue item domain model.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueItem extends AbstractEntity
{
    /**
     * @var string
     */
    protected string $tableName = '';

    /**
     * @var int
     */
    protected int $recordUid = 0;

    /**
     * @var string
     */
    protected string $indexerType = '';

    /**
     * @var int
     */
    protected int $serviceUid = 0;

    /**
     * @var DateTime
     */
    protected DateTime $changed;

    /**
     * @var int
     */
    protected int $priority = 0;

    /**
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * @param string $tableName
     *
     * @return QueueItem
     */
    public function setTableName(string $tableName): QueueItem
    {
        $this->tableName = $tableName;

        return $this;
    }

    /**
     * @return int
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * @param int $recordUid
     *
     * @return QueueItem
     */
    public function setRecordUid(int $recordUid): QueueItem
    {
        $this->recordUid = $recordUid;

        return $this;
    }

    /**
     * @return string
     */
    public function getIndexerType(): string
    {
        return $this->indexerType;
    }

    /**
     * @param string $indexerType
     *
     * @return QueueItem
     */
    public function setIndexerType(string $indexerType): QueueItem
    {
        $this->indexerType = $indexerType;

        return $this;
    }

    /**
     * @return int
     */
    public function getServiceUid(): int
    {
        return $this->serviceUid;
    }

    /**
     * @param int $serviceUid
     *
     * @return QueueItem
     */
    public function setServiceUid(int $serviceUid): QueueItem
    {
        $this->serviceUid = $serviceUid;

        return $this;
    }

    /**
     * @return DateTime
     */
    public function getChanged(): DateTime
    {
        return $this->changed;
    }

    /**
     * @param DateTime $changed
     *
     * @return QueueItem
     */
    public function setChanged(DateTime $changed): QueueItem
    {
        $this->changed = $changed;

        return $this;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * @param int $priority
     *
     * @return QueueItem
     */
    public function setPriority(int $priority): QueueItem
    {
        $this->priority = $priority;

        return $this;
    }
}
