..  include:: /Includes.rst.txt

..  _indexers-content-element:

=======================
Content element indexer
=======================

The content element indexer creates one Algolia record per content
element. This gives more precise results than page records and keeps
records small on pages with a lot of content.

Which content element types are indexed is set with
:confval:`indexing-service-content-element-types`.

..  _indexers-content-element-fields:

Fields
======

In addition to the :ref:`standard fields <indexers-standard-fields>`, the
content element indexer sends:

..  list-table::
    :header-rows: 1
    :widths: 20 80

    *   -   Field
        -   Description
    *   -   ``site``
        -   The domain of the page's site.
    *   -   ``url``
        -   The URL of the page with an anchor to the content element
            (``#c<uid>``), if it can be built.

The mapped content element fields default to:

..  code-block:: typoscript
    :caption: Default field mapping of the content element indexer

    module.tx_typo3searchalgolia.indexer.tt_content.fields {
        header = title
        subheader = subTitle
        bodytext = description
    }

The same mapping is used for the ``content`` field of the
:ref:`page indexer <indexers-page-fields>` when content elements are
included in page records.
