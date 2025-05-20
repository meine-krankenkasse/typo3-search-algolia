<?php

/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\EventListener;

use MeineKrankenkasse\Typo3SearchAlgolia\Constants;
use MeineKrankenkasse\Typo3SearchAlgolia\Event\CreateUniqueDocumentIdEvent;

/**
 * Event listener for generating default document IDs for search engine documents.
 *
 * This listener responds to CreateUniqueDocumentIdEvent events that are dispatched
 * when a document needs a unique identifier for storage in the search engine. It
 * creates a standardized document ID by combining:
 * - The extension name (as a namespace prefix)
 * - The database table name (to identify the record type)
 * - The record UID (to uniquely identify the specific record)
 *
 * The resulting format is: "{extension_name}:{table_name}:{record_uid}"
 *
 * This standardized format ensures that:
 * - Document IDs are unique across different record types
 * - Documents can be traced back to their source records
 * - IDs are consistent and predictable for operations like updates and deletions
 *
 * This listener provides the default ID generation logic, but can be overridden
 * by other listeners with higher priority if custom ID formats are needed.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class CreateDefaultDocumentIdEventListener
{
    /**
     * Processes the document ID creation event and sets a standardized document ID.
     *
     * This method is automatically called by the event dispatcher when a
     * CreateUniqueDocumentIdEvent is dispatched. It performs the following tasks:
     *
     * 1. Retrieves the necessary components for the document ID:
     *    - The extension name from the Constants class
     *    - The table name from the event
     *    - The record UID from the event
     * 2. Combines these components into a standardized format: "{extension_name}:{table_name}:{record_uid}"
     * 3. Sets this formatted string as the document ID on the event object
     *
     * The document ID is used by the search engine to uniquely identify documents
     * and to perform operations like updates and deletions. The standardized format
     * ensures consistency and traceability across the search index.
     *
     * @param CreateUniqueDocumentIdEvent $event The document ID creation event containing table name and record UID
     *
     * @return void
     */
    public function __invoke(CreateUniqueDocumentIdEvent $event): void
    {
        $event->setDocumentId(
            Constants::EXTENSION_NAME . ':' . $event->getTableName() . ':' . $event->getRecordUid()
        );
    }
}
