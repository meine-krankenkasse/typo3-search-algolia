..  include:: /Includes.rst.txt

..  _configuration-indexing-service:

================
Indexing service
================

An indexing service defines which records of one type are indexed and
into which search engine they go. Create a new record of type
:guilabel:`Indexing Service` in the configuration folder.

..  figure:: /Images/Configuration-003.png
    :alt: New record wizard with the record type Indexing Service
    :class: with-border with-shadow
    :zoom: lightbox

    Create a new record of type :guilabel:`Indexing Service`

Enter a title and, optionally, a description. Select the indexer
:guilabel:`Type` and the :guilabel:`Search Engine` created before. The
built-in types are :guilabel:`Pages`, :guilabel:`Content elements`,
:guilabel:`News` (only when EXT:news is installed) and :guilabel:`Files`.
The form then shows the options of the selected type.

..  figure:: /Images/Configuration-004.png
    :alt: Indexing service record form with type and search engine
    :class: with-border with-shadow
    :zoom: lightbox

    Indexing service record

A record type can have several indexing services, for example to index
different page trees into different indexes.

..  warning::
    Frontend user group access restrictions are not evaluated, neither on
    pages, nor on content elements or news records, nor when a page passes
    its restriction on to its subpages. Such records are indexed like
    public records. Keep restricted pages with their subpages, and
    restricted content elements and news records, out of the pages
    selected in the indexing services.

..  warning::
    The hidden state of a page is not applied to news records on it. A
    news record is queued when it is saved, even if its page is hidden. The
    Queue module and the requeue of categories skip hidden subpages of the
    pages that are selected recursively, so they do not queue the news
    records on such pages. Content elements on a hidden page, or on a page
    excluded from the search, are not queued, see :ref:`indexers-queue`.
    Keep pages that must stay out of the search out of the pages selected in
    the indexing services.

..  warning::
    Store the indexing service records outside of page trees that are
    moved to another site. The services are resolved after the move, so a
    service that is stored inside the moved tree can already be taken for
    one of the new site and then cannot remove the documents of the
    former one, see :ref:`indexers-queue`.

..  _configuration-indexing-service-options:

Options by type
===============

..  confval:: Include content elements
    :name: indexing-service-include-content-elements
    :type: boolean
    :Types: Pages

    Also index the content elements of each page. Their fields, as mapped
    in ``module.tx_typo3searchalgolia.indexer.tt_content.fields`` (see
    :ref:`indexers-content-element-fields`), are stored in the page's
    ``content`` attribute, so no separate content element indexing
    service is needed. Records can get large this way, so check the record
    size limit of your Algolia plan. To leave content in specific columns
    out, see :ref:`configuration-exclude-colpos`.

..  confval:: Content element types
    :name: indexing-service-content-element-types
    :type: list
    :Types: Content elements, and Pages with content elements included

    The content element types to index. Defaults to Bullet List, Header,
    HTML, Table, Text, Text & Images and Text & Media.

..  confval:: Page type
    :name: indexing-service-page-type
    :type: list
    :Types: Pages

    Only index pages of the selected page types. Without a selection, all
    page types are included.

..  confval:: Single pages
    :name: indexing-service-single-pages
    :type: list of pages
    :Types: Pages, Content elements, News

    Pages whose records are indexed, without their subpages.

..  confval:: Pages (recursively)
    :name: indexing-service-pages-recursive
    :type: list of pages
    :Types: Pages, Content elements, News

    Pages whose records are indexed, including all subpages.

For the Pages type, pages with :guilabel:`Include in Search` disabled are
not queued, whichever of these two options selects them. If neither is
set, the records of the selected type are not limited by page. The other
options of the type still apply.

..  confval:: File collections
    :name: indexing-service-file-collections
    :type: list of file collections
    :Types: Files

    The file collections whose files are indexed. Files with
    :guilabel:`Include in Search` disabled in their metadata are skipped.
