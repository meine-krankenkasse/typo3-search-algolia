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
 * Domain model for search engine configurations.
 *
 * This class represents a configuration for a specific search engine instance
 * that can be used for indexing content. It contains:
 * - Basic metadata like title and description
 * - The type of search engine to use (e.g., "algolia")
 * - The name of the index where content should be stored
 *
 * Search engine configurations are created in the TYPO3 backend and referenced
 * by indexing services to determine where indexed content should be sent.
 * Multiple indexing services can use the same search engine configuration,
 * allowing different types of content to be indexed in the same search engine.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class SearchEngine extends AbstractEntity
{
    /**
     * Creation date of the search engine configuration record.
     *
     * This property stores when the search engine configuration was initially
     * created in the TYPO3 backend. It is automatically set by TYPO3's DataHandler
     * and is not directly editable by users.
     *
     * @var DateTime
     */
    protected DateTime $crdate;

    /**
     * Last modification timestamp of the search engine configuration record.
     *
     * This property stores when the search engine configuration was last
     * modified in the TYPO3 backend. It is automatically updated by TYPO3's
     * DataHandler whenever the record is changed.
     *
     * @var DateTime
     */
    protected DateTime $tstamp;

    /**
     * Deletion status of the search engine configuration.
     *
     * When set to true, this search engine configuration is considered deleted
     * in the TYPO3 system but is still stored in the database (soft delete).
     * Deleted configurations are not used for indexing operations and are not
     * shown in the backend.
     *
     * @var bool
     */
    protected bool $deleted = false;

    /**
     * Human-readable name of the search engine configuration.
     *
     * This property stores the title that is displayed in the TYPO3 backend
     * to identify this search engine configuration. It should be descriptive
     * of the search engine instance it represents (e.g., "Production Algolia").
     *
     * @var string
     */
    protected string $title = '';

    /**
     * Detailed description of the search engine configuration.
     *
     * This property stores an optional longer description that explains the
     * purpose and configuration of this search engine in more detail.
     * It is displayed in the TYPO3 backend to provide additional context.
     *
     * @var string
     */
    protected string $description = '';

    /**
     * Type identifier of the search engine to use.
     *
     * This property stores the identifier of the search engine type (e.g., "algolia")
     * that determines which search engine implementation will be used for indexing.
     * It corresponds to the subtype registered in the SearchEngineRegistry.
     *
     * @var string
     */
    protected string $engine;

    /**
     * Name of the index in the search engine where content should be stored.
     *
     * This property specifies the name of the index within the search engine
     * where indexed content will be stored. Different indices can be used to
     * separate content for different environments (e.g., "production", "staging")
     * or different purposes (e.g., "main", "products").
     *
     * @var string
     */
    protected string $indexName;

    /**
     * Returns the creation date of the search engine configuration record.
     *
     * This getter method provides access to the timestamp when this search engine
     * configuration was initially created in the TYPO3 backend. It is automatically
     * set by TYPO3's DataHandler and is not directly editable by users.
     *
     * @return DateTime The creation date timestamp
     */
    public function getCrdate(): DateTime
    {
        return $this->crdate;
    }

    /**
     * Sets the creation date of the search engine configuration record.
     *
     * This setter method allows modifying the timestamp when this search engine
     * configuration was initially created. This is typically only used internally
     * by the persistence framework.
     *
     * @param DateTime $crdate The creation date timestamp to set
     *
     * @return SearchEngine The current instance for method chaining
     */
    public function setCrdate(DateTime $crdate): SearchEngine
    {
        $this->crdate = $crdate;

        return $this;
    }

    /**
     * Returns the last modification timestamp of the search engine configuration record.
     *
     * This getter method provides access to the timestamp when this search engine
     * configuration was last modified in the TYPO3 backend. It is automatically
     * updated by TYPO3's DataHandler whenever the record is changed.
     *
     * @return DateTime The last modification timestamp
     */
    public function getTstamp(): DateTime
    {
        return $this->tstamp;
    }

    /**
     * Sets the last modification timestamp of the search engine configuration record.
     *
     * This setter method allows modifying the timestamp when this search engine
     * configuration was last updated. This is typically only used internally
     * by the persistence framework.
     *
     * @param DateTime $tstamp The last modification timestamp to set
     *
     * @return SearchEngine The current instance for method chaining
     */
    public function setTstamp(DateTime $tstamp): SearchEngine
    {
        $this->tstamp = $tstamp;

        return $this;
    }

    /**
     * Returns whether the search engine configuration is marked as deleted.
     *
     * This getter method indicates whether this search engine configuration
     * has been soft-deleted in the TYPO3 system. Deleted configurations are still
     * stored in the database but are completely excluded from all operations and
     * backend views.
     *
     * @return bool TRUE if the search engine configuration is deleted, FALSE otherwise
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Sets the deletion status of the search engine configuration.
     *
     * This setter method allows changing whether this search engine configuration
     * should be marked as deleted in the TYPO3 system. This implements a soft-delete
     * approach where the record remains in the database but is excluded from all operations.
     *
     * @param bool $deleted TRUE to mark the search engine configuration as deleted, FALSE otherwise
     *
     * @return SearchEngine The current instance for method chaining
     */
    public function setDeleted(bool $deleted): SearchEngine
    {
        $this->deleted = $deleted;

        return $this;
    }

    /**
     * Returns the human-readable title of the search engine configuration.
     *
     * This getter method provides access to the title that identifies this search
     * engine configuration in the TYPO3 backend. The title is displayed in selection
     * lists, tables, and other UI elements to help administrators identify the
     * search engine configuration.
     *
     * @return string The title of the search engine configuration
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Sets the human-readable title of the search engine configuration.
     *
     * This setter method allows changing the title that identifies this search
     * engine configuration in the TYPO3 backend. The title should be descriptive
     * of the search engine instance it represents (e.g., "Production Algolia").
     *
     * @param string $title The new title for the search engine configuration
     *
     * @return SearchEngine The current instance for method chaining
     */
    public function setTitle(string $title): SearchEngine
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Returns the detailed description of the search engine configuration.
     *
     * This getter method provides access to the longer description that explains
     * the purpose and configuration of this search engine in more detail.
     * The description provides additional context beyond the title and is displayed
     * in the TYPO3 backend to help administrators understand the configuration.
     *
     * @return string The description of the search engine configuration
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Sets the detailed description of the search engine configuration.
     *
     * This setter method allows changing the longer description that explains
     * the purpose and configuration of this search engine. A good description
     * should provide information about what the search engine is used for,
     * any special configuration details, and other relevant information.
     *
     * @param string $description The new description for the search engine configuration
     *
     * @return SearchEngine The current instance for method chaining
     */
    public function setDescription(string $description): SearchEngine
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Returns the type identifier of the search engine to use.
     *
     * This getter method provides access to the identifier of the search engine
     * type (e.g., "algolia") that determines which search engine implementation
     * will be used for indexing. This value corresponds to the subtype registered
     * in the SearchEngineRegistry.
     *
     * @return string The search engine type identifier
     */
    public function getEngine(): string
    {
        return $this->engine;
    }

    /**
     * Sets the type identifier of the search engine to use.
     *
     * This setter method allows changing which search engine implementation
     * will be used for indexing. The value must correspond to a subtype registered
     * in the SearchEngineRegistry, otherwise no valid search engine service
     * can be created for this configuration.
     *
     * @param string $engine The search engine type identifier (e.g., "algolia")
     *
     * @return SearchEngine The current instance for method chaining
     */
    public function setEngine(string $engine): SearchEngine
    {
        $this->engine = $engine;

        return $this;
    }

    /**
     * Returns the name of the index in the search engine where content should be stored.
     *
     * This getter method provides access to the name of the index within the search
     * engine where indexed content will be stored. The index name is used when
     * communicating with the search engine API to identify which specific index
     * should receive the indexed content.
     *
     * @return string The index name
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * Sets the name of the index in the search engine where content should be stored.
     *
     * This setter method allows changing which index within the search engine
     * should receive the indexed content. Different indices can be used to
     * separate content for different environments (e.g., "production", "staging")
     * or different purposes (e.g., "main", "products").
     *
     * @param string $indexName The index name to use
     *
     * @return SearchEngine The current instance for method chaining
     */
    public function setIndexName(string $indexName): SearchEngine
    {
        $this->indexName = $indexName;

        return $this;
    }
}
