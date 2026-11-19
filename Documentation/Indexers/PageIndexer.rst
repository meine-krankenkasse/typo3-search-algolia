..  include:: /Includes.rst.txt

..  _indexers-page:

============
Page indexer
============

The page indexer indexes TYPO3 pages, optionally together with their
content elements (see :confval:`indexing-service-include-content-elements`).

..  _indexers-page-exclude:

Excluding a page
================

Disable :guilabel:`Include in Search` in the page properties to keep a
page out of the index. The option is on the :guilabel:`Behavior` tab.

..  _indexers-page-exclude-colpos:

Excluding content columns
=========================

The TypoScript option ``excludeColPos`` leaves the content elements of the
given columns out of the page's ``content`` field. It only has an effect if
:confval:`indexing-service-include-content-elements` is set.

The value is a comma-separated list of ``colPos`` values in plain integer
notation. Entries in any other notation, such as letters, decimals, leading
zeros or a plus sign, are ignored, so a typo is never read as column ``0``. If
the option is empty or not set, content elements of every column are indexed.

..  code-block:: typoscript
    :caption: Leave the content elements of column 9999 out of the page content

    module.tx_typo3searchalgolia.indexer.pages.excludeColPos = 9999

The extension does not assign a value itself, so a value set by a site package
stays in effect.

..  _indexers-page-fields:

Fields
======

In addition to the :ref:`standard fields <indexers-standard-fields>`, the
page indexer sends:

..  list-table::
    :header-rows: 1
    :widths: 20 80

    *   -   Field
        -   Description
    *   -   ``site``
        -   The domain of the page's site.
    *   -   ``url``
        -   The URL of the page, if the page belongs to a site.
    *   -   ``content``
        -   The text of the page's content elements, only if
            :confval:`indexing-service-include-content-elements` is set.

For pages, ``changed`` is the page's ``SYS_LASTCHANGED`` value. It holds
the latest change of the page or of its content as of the last frontend
rendering of the page. If the page has not been rendered yet, its own
last change time (``tstamp``) is used.

The mapped page fields default to:

..  code-block:: typoscript
    :caption: Default field mapping of the page indexer

    module.tx_typo3searchalgolia.indexer.pages.fields {
        title = title
        subtitle = subTitle
        nav_title = navTitle
        description = description
        abstract = teaser
        author = author
        keywords = keywords
    }
