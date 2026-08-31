/**
 * This file is part of the package meine-krankenkasse/typo3-search-algolia.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

import DocumentService from "@typo3/core/document-service.js";

/**
 * Attribute overview module for the TYPO3 backend.
 *
 * This class wires up the manual record selectors of the "Attribute
 * Overview" backend module. The backend's Content-Security-Policy blocks
 * inline event handlers, so the record selectors cannot use an inline
 * "onchange" attribute to resubmit their form. Instead, this module
 * attaches the change listener from an imported JavaScript module.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
class AttributeOverviewModule {
    /**
     * The CSS class used to identify the record selectors this module
     * should handle.
     *
     * @type {string}
     */
    selectorClass = "tx-typo3searchalgolia-attribute-overview-select";

    /**
     * Attaches a "change" event listener to every record selector present
     * in the module, submitting the enclosing form once the DOM is ready.
     *
     * @returns {void}
     */
    initialize = function () {
        DocumentService.ready().then((document) => {
            document
                .querySelectorAll("." + this.selectorClass)
                .forEach((select) => {
                    select.addEventListener("change", () => {
                        select.form?.submit();
                    });
                });
        });
    };

    constructor() {
        this.initialize();
    }
}

export default new AttributeOverviewModule();
