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

..  figure:: /Images/PageIndexer-001.png
    :alt: Page properties, Behaviour tab with the Include in Search toggle
    :class: with-border with-shadow
    :zoom: lightbox

    Exclude a page from indexing

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
        -   The URL of the page, if it can be built.
    *   -   ``categories``
        -   The titles of the categories assigned to the page.
    *   -   ``content``
        -   The text of the page's content elements, only if
            :confval:`indexing-service-include-content-elements` is set.

For pages, ``changed`` is the last change of the page or of its content.

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
