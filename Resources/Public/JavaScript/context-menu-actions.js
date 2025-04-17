/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

import AjaxRequest from "@typo3/core/ajax/ajax-request.js";

class ContextMenuActions {
    enqueueOne = function (table, identifier, context) {
        new AjaxRequest(context.actionUrl)
            .withQueryArguments({
                target: encodeURIComponent(identifier)
            })
            .get()
            .finally((() => {
                top.TYPO3.Backend.ContentContainer.refresh()
            }));
    };
}

export default new ContextMenuActions();
