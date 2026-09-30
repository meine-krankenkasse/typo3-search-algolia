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
page out of the index. The option is on the :guilabel:`Behaviour` tab.

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

To leave content elements in specific columns out of the ``content``
field, see :ref:`configuration-exclude-colpos`.

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
