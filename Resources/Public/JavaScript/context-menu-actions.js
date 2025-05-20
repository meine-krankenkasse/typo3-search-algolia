/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

import AjaxRequest from "@typo3/core/ajax/ajax-request.js";

/**
 * Context menu actions for the TYPO3 backend.
 *
 * This class provides methods that can be used as actions in the TYPO3 backend context menu.
 * These actions allow users to interact with the Algolia search indexing functionality
 * directly from the page tree or list view.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class ContextMenuActions {
    /**
     * Enqueues a single item for indexing.
     *
     * This method sends an AJAX request to enqueue a specific record for indexing
     * and refreshes the content container after the request completes.
     *
     * @param {string} table      The database table name of the record
     * @param {string} identifier The identifier of the record to be indexed
     * @param {Object} context    The context object containing action URL and other information
     *
     * @returns {void}
     */
    enqueueOne = function (table, identifier, context) {
        new AjaxRequest(context.actionUrl)
            .withQueryArguments({
                target: encodeURIComponent(identifier)
            })
            .get()
            .then(() => {
                console.log(`Record ${identifier} from table ${table} successfully enqueued for indexing`);
            })
            .catch((error) => {
                console.error(`Failed to enqueue record ${identifier} from table ${table}:`, error);
            })
            .finally(() => {
                top.TYPO3.Backend.ContentContainer.refresh();
            });
    };
}

export default new ContextMenuActions();
