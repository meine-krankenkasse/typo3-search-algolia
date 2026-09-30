..  include:: /Includes.rst.txt

..  _indexers:

========
Indexers
========

An indexer knows how to read, process and index the records of one
record type. There is exactly one indexer per record type, and any number
of indexing services (see :ref:`configuration-indexing-service`) can
configure it.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    PageIndexer
    ContentElementIndexer
    NewsIndexer
    FileIndexer

..  _indexers-standard-fields:

Standard fields
===============

Every indexer sends these fields to Algolia:

..  list-table::
    :header-rows: 1
    :widths: 20 80

    *   -   Field
        -   Description
    *   -   ``uid``
        -   The UID of the record.
    *   -   ``pid``
        -   The page ID of the record.
    *   -   ``type``
        -   The record type, i.e. the table name.
    *   -   ``indexed``
        -   The time of indexing.
    *   -   ``created``
        -   The creation time of the record, if available.
    *   -   ``changed``
        -   The time of the last change of the record, if available.

..  _indexers-field-mapping:

Field mapping
=============

Which record fields are sent to Algolia, and under which attribute name,
is configured per table in TypoScript under
``module.tx_typo3searchalgolia.indexer.<table>.fields``, in the form
``<record field> = <attribute name>``. The defaults are listed on the
page of each indexer.

..  warning::
    The console command ``mkk:queue:index:worker``, which sends the
    records to Algolia, runs without a site context. It reads only the
    TypoScript shipped with the extension, so changes to the mapping in
    your site TypoScript are not applied when records are indexed. The
    :guilabel:`Attributes` backend module shows the mapping of the site,
    which can therefore differ from the indexed records.

..  note::
    Anyone holding the search key of your frontend can retrieve every
    indexed attribute. Hide personal data such as ``authorEmail`` with the
    Algolia index setting ``unretrievableAttributes``.

..  _indexers-queue:

When records are indexed
========================

Records are indexed through the indexing queue:

*   Creating or updating a record in the backend queues it for
    reindexing. A hidden record, or one with :guilabel:`Include in Search`
    disabled, is removed from the queue and from the index instead.
    Deleting a record removes it from the queue and from the index. Only
    indexing services stored in the same site as the record take part,
    except for files, see :ref:`configuration-search-engine`.
*   The :guilabel:`Queue` backend module queues all records of the
    selected indexing services. It skips hidden subpages of the pages
    selected in :guilabel:`Pages (recursively)`, together with their
    subpages and the records on them.
*   The console command ``mkk:queue:index:worker``, usually run as a
    scheduler task, processes the queue and sends the records to Algolia.
