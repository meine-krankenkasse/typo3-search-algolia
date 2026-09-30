..  include:: /Includes.rst.txt

..  _configuration-exclude-colpos:

=================================
Excluding content element columns
=================================

With :confval:`indexing-service-include-content-elements` enabled, the page
indexer adds the text of every visible content element found on a page to
the page's ``content`` attribute. This includes content stored in columns
that the page templates of the site never render, for example a
project-specific storage column for unused elements.

To leave such columns out, list their ``colPos`` values in the TypoScript
option ``excludeColPos``:

..  code-block:: typoscript
    :caption: EXT:my_site_package/Configuration/TypoScript/setup.typoscript

    module.tx_typo3searchalgolia.indexer.pages.excludeColPos = 9999

The example value is specific to a site. Only list columns that your page
templates do not render. Column ``0`` is the default main content column of
TYPO3, so listing it on a site that renders it removes the main content
from the index.

..  confval:: excludeColPos
    :name: typoscript-exclude-colpos
    :type: comma-separated list of integers
    :default: not set

    The ``colPos`` values whose content elements are left out of the
    ``content`` attribute of page records. Whitespace around the entries is
    ignored. An entry that is not a plain whole number is ignored as well,
    for example letters, decimals, numbers with leading zeros or a plus
    sign. A typo can therefore never be read as ``colPos`` 0. Because such
    an entry is dropped silently, check the result after changing the
    option.

..  _configuration-exclude-colpos-behavior:

How the option behaves
======================

*   Any ``colPos`` can be listed, including columns that are not part of a
    backend layout, such as the child columns of container elements.
*   Only the listed columns are excluded. A column that is not listed, for
    example a storage column added later or the child columns of a disabled
    container element, stays indexed.
*   If the option is not set, nothing is excluded and every ``colPos`` is
    indexed. Do not assign an empty value in your own TypoScript, leave the
    option out instead.
*   The option applies to all indexing services of type
    :guilabel:`Pages` that have :guilabel:`Include content elements`
    enabled.
*   The :ref:`content element indexer <indexers-content-element>` is not
    affected. It keeps indexing all content elements regardless of
    ``colPos``. If you also run an indexing service of that type, content in
    the excluded columns stays searchable through it.

..  _configuration-exclude-colpos-where:

Where to set the option
=======================

Set the option in the TypoScript of your site package, so it is versioned
and identical on every environment. A value entered in the :guilabel:`Setup`
field of a TypoScript template record in the backend is stored in the
database. It is not deployed with the code and has to be maintained on each
environment separately. By default only administrators can edit template
records.

When the indexing runs on the command line, as the queue worker does, the
TypoScript is resolved for the first site root page and not for the site of
the page being indexed. On a multi-site installation, set the option in
TypoScript that applies to all sites, such as a site package that the root
template of every site includes.

..  _configuration-exclude-colpos-reindex:

Reindexing after a change
=========================

Content that was indexed before the option changed stays in the index until
the pages are indexed again. Queue the pages again after changing the
option, see :ref:`indexers-queue`.
