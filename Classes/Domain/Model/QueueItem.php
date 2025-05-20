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
 * Domain model for indexing queue items.
 *
 * This class represents an item in the indexing queue that is waiting to be
 * processed by the indexing system. Each queue item contains:
 * - A reference to the database record that needs to be indexed (table name and UID)
 * - A reference to the indexing service that should process this item
 * - Metadata like when the record was last changed and its processing priority
 *
 * Queue items are created when content is added or modified in TYPO3 and are
 * processed by scheduled tasks that send the content to search engines for indexing.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class QueueItem extends AbstractEntity
{
    /**
     * Database table name of the record to be indexed.
     *
     * This property stores the name of the database table (e.g., "pages", "tt_content",
     * "sys_file_metadata") that contains the record that needs to be indexed.
     * Together with recordUid, it uniquely identifies the record in the TYPO3 database.
     *
     * @var string
     */
    protected string $tableName = '';

    /**
     * Unique identifier of the record to be indexed.
     *
     * This property stores the UID of the database record that needs to be indexed.
     * Together with tableName, it uniquely identifies the record in the TYPO3 database.
     *
     * @var int
     */
    protected int $recordUid = 0;

    /**
     * Unique identifier of the indexing service to use.
     *
     * This property stores the UID of the IndexingService that should process
     * this queue item. It determines which indexing configuration and search
     * engine will be used when indexing the record.
     *
     * @var int
     */
    protected int $serviceUid = 0;

    /**
     * Timestamp when the record was last changed.
     *
     * This property stores when the database record was last modified. It is used
     * to determine if the indexed version is outdated and needs to be updated.
     * Records with more recent changes are typically processed first.
     *
     * @var DateTime
     */
    protected DateTime $changed;

    /**
     * Processing priority of the queue item.
     *
     * This property determines the order in which queue items are processed.
     * Items with higher priority values are processed before items with lower
     * priority values. This allows important content to be indexed faster.
     *
     * @var int
     */
    protected int $priority = 0;

    /**
     * Returns the database table name of the record to be indexed.
     *
     * This getter method provides access to the name of the database table
     * (e.g., "pages", "tt_content", "sys_file_metadata") that contains the
     * record that needs to be indexed. Together with recordUid, this value
     * uniquely identifies the record in the TYPO3 database.
     *
     * @return string The database table name
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Sets the database table name of the record to be indexed.
     *
     * This setter method allows specifying which database table contains the
     * record that needs to be indexed. The table name determines which indexer
     * implementation will be used to process this queue item.
     *
     * @param string $tableName The database table name (e.g., "pages", "tt_content")
     *
     * @return QueueItem The current instance for method chaining
     */
    public function setTableName(string $tableName): QueueItem
    {
        $this->tableName = $tableName;

        return $this;
    }

    /**
     * Returns the unique identifier of the record to be indexed.
     *
     * This getter method provides access to the UID of the database record
     * that needs to be indexed. Together with tableName, this value uniquely
     * identifies the record in the TYPO3 database that will be processed
     * when this queue item is executed.
     *
     * @return int The record UID
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * Sets the unique identifier of the record to be indexed.
     *
     * This setter method allows specifying which database record should be
     * indexed when this queue item is processed. The record UID, combined with
     * the table name, uniquely identifies the content to be indexed.
     *
     * @param int $recordUid The record UID
     *
     * @return QueueItem The current instance for method chaining
     */
    public function setRecordUid(int $recordUid): QueueItem
    {
        $this->recordUid = $recordUid;

        return $this;
    }

    /**
     * Returns the unique identifier of the indexing service to use.
     *
     * This getter method provides access to the UID of the IndexingService
     * that should process this queue item. The indexing service determines
     * which indexing configuration and search engine will be used when
     * processing this queue item.
     *
     * @return int The indexing service UID
     */
    public function getServiceUid(): int
    {
        return $this->serviceUid;
    }

    /**
     * Sets the unique identifier of the indexing service to use.
     *
     * This setter method allows specifying which indexing service should
     * process this queue item. The indexing service determines the search
     * engine, index name, and other configuration details used during indexing.
     *
     * @param int $serviceUid The indexing service UID
     *
     * @return QueueItem The current instance for method chaining
     */
    public function setServiceUid(int $serviceUid): QueueItem
    {
        $this->serviceUid = $serviceUid;

        return $this;
    }

    /**
     * Returns the timestamp when the record was last changed.
     *
     * This getter method provides access to the timestamp when the database
     * record was last modified. This information is used to determine if the
     * indexed version is outdated and needs to be updated, and to prioritize
     * processing of recently changed records.
     *
     * @return DateTime The timestamp when the record was last changed
     */
    public function getChanged(): DateTime
    {
        return $this->changed;
    }

    /**
     * Sets the timestamp when the record was last changed.
     *
     * This setter method allows specifying when the database record was last
     * modified. This timestamp is used for sorting queue items during processing,
     * with more recently changed records typically being processed first to
     * ensure the search index contains the most up-to-date content.
     *
     * @param DateTime $changed The timestamp when the record was last changed
     *
     * @return QueueItem The current instance for method chaining
     */
    public function setChanged(DateTime $changed): QueueItem
    {
        $this->changed = $changed;

        return $this;
    }

    /**
     * Returns the processing priority of the queue item.
     *
     * This getter method provides access to the priority value that determines
     * the order in which queue items are processed. Items with higher priority
     * values are processed before items with lower priority values, allowing
     * important content to be indexed faster.
     *
     * @return int The priority value
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Sets the processing priority of the queue item.
     *
     * This setter method allows specifying the priority value that determines
     * the order in which queue items are processed. Higher values indicate
     * higher priority and will cause this item to be processed earlier in the
     * queue. This is useful for ensuring that important content is indexed
     * before less important content.
     *
     * @param int $priority The priority value (higher values = higher priority)
     *
     * @return QueueItem The current instance for method chaining
     */
    public function setPriority(int $priority): QueueItem
    {
        $this->priority = $priority;

        return $this;
    }
}
