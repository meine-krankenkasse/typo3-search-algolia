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
 * Domain model for indexing service configurations.
 *
 * This class represents a configuration for indexing specific types of content
 * in search engines. It defines:
 * - Which content type should be indexed (pages, content elements, files, etc.)
 * - Which search engine should be used for indexing
 * - What filtering criteria should be applied (page types, content element types)
 * - Which pages or file collections should be included
 *
 * Indexing services are created and configured in the TYPO3 backend and used by
 * the indexing system to determine what content should be added to search indices.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class IndexingService extends AbstractEntity
{
    /**
     * Creation date of the indexing service record.
     *
     * This property stores when the indexing service configuration was initially
     * created in the TYPO3 backend. It is automatically set by TYPO3's DataHandler
     * and is not directly editable by users.
     */
    protected DateTime $crdate;

    /**
     * Last modification timestamp of the indexing service record.
     *
     * This property stores when the indexing service configuration was last
     * modified in the TYPO3 backend. It is automatically updated by TYPO3's
     * DataHandler whenever the record is changed.
     */
    protected DateTime $tstamp;

    /**
     * Visibility status of the indexing service.
     *
     * When set to true, this indexing service is hidden in the TYPO3 backend
     * and will not be used for indexing operations. This allows administrators
     * to temporarily disable specific indexing configurations without deleting them.
     */
    protected bool $hidden = false;

    /**
     * Deletion status of the indexing service.
     *
     * When set to true, this indexing service is considered deleted in the TYPO3
     * system but is still stored in the database (soft delete). Deleted indexing
     * services are not used for indexing operations and are not shown in the backend.
     */
    protected bool $deleted = false;

    /**
     * Human-readable name of the indexing service.
     *
     * This property stores the title that is displayed in the TYPO3 backend
     * to identify this indexing service configuration. It should be descriptive
     * of what content this service indexes (e.g., "Index all news pages").
     */
    protected string $title = '';

    /**
     * Detailed description of the indexing service.
     *
     * This property stores an optional longer description that explains the
     * purpose and configuration of this indexing service in more detail.
     * It is displayed in the TYPO3 backend to provide additional context.
     */
    protected string $description = '';

    /**
     * Content type that this indexing service is configured to index.
     *
     * This property stores the database table name (e.g., "pages", "tt_content",
     * "sys_file_metadata") that identifies what type of content this indexing
     * service will process. It determines which indexer implementation will be used.
     */
    protected string $type;

    /**
     * Reference to the search engine configuration to use for indexing.
     *
     * This property stores a relation to the SearchEngine model that defines
     * which search engine instance should receive the indexed content from this
     * indexing service. It determines the destination for all indexed content.
     */
    protected SearchEngine $searchEngine;

    /**
     * Flag indicating whether content elements should be included in page indexing.
     *
     * When this property is set to true and the indexing service is configured
     * for pages, the content of all content elements on each page will be included
     * in the page's search index document. This creates more comprehensive search
     * results but may result in larger index documents.
     */
    protected bool $includeContentElements;

    /**
     * Comma-separated list of content element types to include in indexing.
     *
     * This property allows filtering which types of content elements (CType)
     * should be indexed. When empty, all content element types are included.
     * When specified, only the listed types will be indexed (e.g., "text,textpic,image").
     */
    protected string $contentElementTypes = '';

    /**
     * Comma-separated list of page types (doktype) to include in indexing.
     *
     * This property allows filtering which types of pages should be indexed.
     * When empty, all page types are included. When specified, only pages with
     * the listed doktypes will be indexed (e.g., "1,4" for standard pages and shortcuts).
     */
    protected string $pagesDoktype = '';

    /**
     * Comma-separated list of specific page UIDs to include in indexing.
     *
     * This property allows explicitly selecting individual pages for indexing
     * by their unique identifiers. When specified, only the listed pages will
     * be considered for indexing, regardless of other filtering criteria.
     */
    protected string $pagesSingle = '';

    /**
     * Comma-separated list of page UIDs to include recursively in indexing.
     *
     * This property allows selecting entire page trees for indexing by specifying
     * the root page UIDs. When specified, the listed pages and all their subpages
     * will be considered for indexing, subject to other filtering criteria.
     */
    protected string $pagesRecursive = '';

    /**
     * Comma-separated list of file collection UIDs to include in indexing.
     *
     * This property is used when the indexing service is configured for files.
     * It specifies which file collections should be processed for indexing.
     * Only files within the listed collections will be considered for indexing.
     */
    protected string $fileCollections = '';

    /**
     * Returns the creation date of the indexing service record.
     *
     * This getter method provides access to the timestamp when this indexing
     * service configuration was initially created in the TYPO3 backend.
     *
     * @return DateTime The creation date timestamp
     */
    public function getCrdate(): DateTime
    {
        return $this->crdate;
    }

    /**
     * Sets the creation date of the indexing service record.
     *
     * This setter method allows modifying the timestamp when this indexing
     * service configuration was initially created. This is typically only
     * used internally by the persistence framework.
     *
     * @param DateTime $crdate The creation date timestamp to set
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setCrdate(DateTime $crdate): IndexingService
    {
        $this->crdate = $crdate;

        return $this;
    }

    /**
     * Returns the last modification timestamp of the indexing service record.
     *
     * This getter method provides access to the timestamp when this indexing
     * service configuration was last modified in the TYPO3 backend.
     *
     * @return DateTime The last modification timestamp
     */
    public function getTstamp(): DateTime
    {
        return $this->tstamp;
    }

    /**
     * Sets the last modification timestamp of the indexing service record.
     *
     * This setter method allows modifying the timestamp when this indexing
     * service configuration was last updated. This is typically only
     * used internally by the persistence framework.
     *
     * @param DateTime $tstamp The last modification timestamp to set
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setTstamp(DateTime $tstamp): IndexingService
    {
        $this->tstamp = $tstamp;

        return $this;
    }

    /**
     * Returns whether the indexing service is hidden.
     *
     * This getter method indicates whether this indexing service configuration
     * is currently hidden in the TYPO3 backend and excluded from indexing operations.
     * Hidden services are stored in the database but not actively used for indexing.
     *
     * @return bool TRUE if the indexing service is hidden, FALSE otherwise
     */
    public function isHidden(): bool
    {
        return $this->hidden;
    }

    /**
     * Sets the hidden status of the indexing service.
     *
     * This setter method allows changing whether this indexing service configuration
     * should be hidden in the TYPO3 backend and excluded from indexing operations.
     * Setting this to TRUE effectively disables the indexing service without deleting it.
     *
     * @param bool $hidden TRUE to hide the indexing service, FALSE to make it visible
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setHidden(bool $hidden): IndexingService
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Returns whether the indexing service is marked as deleted.
     *
     * This getter method indicates whether this indexing service configuration
     * has been soft-deleted in the TYPO3 system. Deleted services are still stored
     * in the database but are completely excluded from all operations and backend views.
     *
     * @return bool TRUE if the indexing service is deleted, FALSE otherwise
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Sets the deletion status of the indexing service.
     *
     * This setter method allows changing whether this indexing service configuration
     * should be marked as deleted in the TYPO3 system. This implements a soft-delete
     * approach where the record remains in the database but is excluded from all operations.
     *
     * @param bool $deleted TRUE to mark the indexing service as deleted, FALSE otherwise
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setDeleted(bool $deleted): IndexingService
    {
        $this->deleted = $deleted;

        return $this;
    }

    /**
     * Returns the human-readable title of the indexing service.
     *
     * This getter method provides access to the title that identifies this indexing
     * service configuration in the TYPO3 backend. The title is displayed in selection
     * lists, tables, and other UI elements to help administrators identify the service.
     *
     * @return string The title of the indexing service
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Sets the human-readable title of the indexing service.
     *
     * This setter method allows changing the title that identifies this indexing
     * service configuration in the TYPO3 backend. The title should be descriptive
     * of what content this service indexes (e.g., "Index all news pages").
     *
     * @param string $title The new title for the indexing service
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setTitle(string $title): IndexingService
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Returns the detailed description of the indexing service.
     *
     * This getter method provides access to the longer description that explains
     * the purpose and configuration of this indexing service in more detail.
     * The description provides additional context beyond the title and is displayed
     * in the TYPO3 backend to help administrators understand the service's purpose.
     *
     * @return string The description of the indexing service
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Sets the detailed description of the indexing service.
     *
     * This setter method allows changing the longer description that explains
     * the purpose and configuration of this indexing service. A good description
     * should provide information about what content is indexed, any special
     * filtering applied, and the intended use of the indexed content.
     *
     * @param string $description The new description for the indexing service
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setDescription(string $description): IndexingService
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Returns the content type that this indexing service is configured to index.
     *
     * This getter method provides access to the database table name (e.g., "pages",
     * "tt_content", "sys_file_metadata") that identifies what type of content this
     * indexing service will process. The type determines which indexer implementation
     * will be used when processing records for this service.
     *
     * @return string The database table name representing the content type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets the content type that this indexing service should index.
     *
     * This setter method allows changing the database table name that identifies
     * what type of content this indexing service will process. Changing the type
     * will cause a different indexer implementation to be used when processing
     * records for this service.
     *
     * @param string $type The database table name representing the content type
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setType(string $type): IndexingService
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns the search engine configuration to use for indexing.
     *
     * This getter method provides access to the SearchEngine model that defines
     * which search engine instance should receive the indexed content from this
     * indexing service. The search engine configuration contains information like
     * the engine type (e.g., "algolia") and the index name where content will be stored.
     *
     * @return SearchEngine The search engine configuration
     */
    public function getSearchEngine(): SearchEngine
    {
        return $this->searchEngine;
    }

    /**
     * Sets the search engine configuration to use for indexing.
     *
     * This setter method allows changing which search engine instance should
     * receive the indexed content from this indexing service. Changing the
     * search engine configuration will cause indexed content to be sent to
     * a different search engine or index.
     *
     * @param SearchEngine $searchEngine The search engine configuration to use
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setSearchEngine(SearchEngine $searchEngine): IndexingService
    {
        $this->searchEngine = $searchEngine;

        return $this;
    }

    /**
     * Returns whether content elements should be included in page indexing.
     *
     * This getter method indicates whether this indexing service is configured
     * to include the content of all content elements on each page in the page's
     * search index document. When TRUE and the indexing service is configured for
     * pages, the content elements' text will be included in the page document.
     *
     * @return bool TRUE if content elements should be included, FALSE otherwise
     */
    public function isIncludeContentElements(): bool
    {
        return $this->includeContentElements;
    }

    /**
     * Sets whether content elements should be included in page indexing.
     *
     * This setter method allows changing whether this indexing service should
     * include the content of all content elements on each page in the page's
     * search index document. Setting this to TRUE creates more comprehensive search
     * results but may result in larger index documents.
     *
     * @param bool $includeContentElements TRUE to include content elements, FALSE otherwise
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setIncludeContentElements(bool $includeContentElements): IndexingService
    {
        $this->includeContentElements = $includeContentElements;

        return $this;
    }

    /**
     * Returns the comma-separated list of content element types to include in indexing.
     *
     * This getter method provides access to the list of content element types (CType)
     * that should be indexed by this service. When empty, all content element types
     * are included. When specified, only the listed types will be indexed.
     *
     * @return string Comma-separated list of content element types (e.g., "text,textpic,image")
     */
    public function getContentElementTypes(): string
    {
        return $this->contentElementTypes;
    }

    /**
     * Sets the comma-separated list of content element types to include in indexing.
     *
     * This setter method allows filtering which types of content elements (CType)
     * should be indexed by this service. When set to an empty string, all content
     * element types are included. When specified, only the listed types will be indexed.
     *
     * @param string $contentElementTypes Comma-separated list of content element types (e.g., "text,textpic,image")
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setContentElementTypes(string $contentElementTypes): IndexingService
    {
        $this->contentElementTypes = $contentElementTypes;

        return $this;
    }

    /**
     * Returns the comma-separated list of page types to include in indexing.
     *
     * This getter method provides access to the list of page types (doktype)
     * that should be indexed by this service. When empty, all page types are
     * included. When specified, only pages with the listed doktypes will be indexed.
     *
     * @return string Comma-separated list of page types (e.g., "1,4" for standard pages and shortcuts)
     */
    public function getPagesDoktype(): string
    {
        return $this->pagesDoktype;
    }

    /**
     * Sets the comma-separated list of page types to include in indexing.
     *
     * This setter method allows filtering which types of pages should be indexed
     * by this service. When set to an empty string, all page types are included.
     * When specified, only pages with the listed doktypes will be indexed.
     *
     * @param string $pagesDoktype Comma-separated list of page types (e.g., "1,4" for standard pages and shortcuts)
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setPagesDoktype(string $pagesDoktype): IndexingService
    {
        $this->pagesDoktype = $pagesDoktype;

        return $this;
    }

    /**
     * Returns the comma-separated list of specific page UIDs to include in indexing.
     *
     * This getter method provides access to the list of individual pages that
     * should be indexed by this service. When specified, only the listed pages will
     * be considered for indexing, regardless of other filtering criteria.
     *
     * @return string Comma-separated list of page UIDs (e.g., "42,56,78")
     */
    public function getPagesSingle(): string
    {
        return $this->pagesSingle;
    }

    /**
     * Sets the comma-separated list of specific page UIDs to include in indexing.
     *
     * This setter method allows explicitly selecting individual pages for indexing
     * by their unique identifiers. When specified, only the listed pages will
     * be considered for indexing, regardless of other filtering criteria.
     *
     * @param string $pagesSingle Comma-separated list of page UIDs (e.g., "42,56,78")
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setPagesSingle(string $pagesSingle): IndexingService
    {
        $this->pagesSingle = $pagesSingle;

        return $this;
    }

    /**
     * Returns the comma-separated list of page UIDs to include recursively in indexing.
     *
     * This getter method provides access to the list of root page UIDs that define
     * entire page trees for indexing. When specified, the listed pages and all their
     * subpages will be considered for indexing, subject to other filtering criteria.
     *
     * @return string Comma-separated list of root page UIDs (e.g., "1,42")
     */
    public function getPagesRecursive(): string
    {
        return $this->pagesRecursive;
    }

    /**
     * Sets the comma-separated list of page UIDs to include recursively in indexing.
     *
     * This setter method allows selecting entire page trees for indexing by specifying
     * the root page UIDs. When specified, the listed pages and all their subpages
     * will be considered for indexing, subject to other filtering criteria like
     * page types (doktype).
     *
     * @param string $pagesRecursive Comma-separated list of root page UIDs (e.g., "1,42")
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setPagesRecursive(string $pagesRecursive): IndexingService
    {
        $this->pagesRecursive = $pagesRecursive;

        return $this;
    }

    /**
     * Returns the comma-separated list of file collection UIDs to include in indexing.
     *
     * This getter method provides access to the list of file collections that should
     * be processed for indexing when this service is configured for files. Only files
     * within the listed collections will be considered for indexing.
     *
     * @return string Comma-separated list of file collection UIDs (e.g., "3,7,12")
     */
    public function getFileCollections(): string
    {
        return $this->fileCollections;
    }

    /**
     * Sets the comma-separated list of file collection UIDs to include in indexing.
     *
     * This setter method allows specifying which file collections should be processed
     * for indexing when this service is configured for files. Only files within the
     * listed collections will be considered for indexing, subject to other filtering
     * criteria like file extensions.
     *
     * @param string $fileCollections Comma-separated list of file collection UIDs (e.g., "3,7,12")
     *
     * @return IndexingService The current instance for method chaining
     */
    public function setFileCollections(string $fileCollections): IndexingService
    {
        $this->fileCollections = $fileCollections;

        return $this;
    }
}
