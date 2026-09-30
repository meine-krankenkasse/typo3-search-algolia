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

..  _indexers-queue:

When records are indexed
========================

Records are indexed through the indexing queue:

*   Creating or updating a record in the backend queues it automatically.
    Deleting a record removes it from the queue and from the index.
*   The :guilabel:`Queue` backend module queues all records of the
    selected indexing services.
*   The console command ``mkk:queue:index:worker``, usually run as a
    scheduler task, processes the queue and sends the records to Algolia.
