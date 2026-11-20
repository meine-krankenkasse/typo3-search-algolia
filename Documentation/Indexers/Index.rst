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
    When the console command ``mkk:queue:index:worker``, which sends the
    records to Algolia, runs on the command line (cron or the scheduler
    command), it has no request. It reads the TypoScript of the first
    website in the page tree, together with the conditions that apply to
    that website. The TypoScript of any further website is not read, so
    settings that differ between websites are not applied when records of
    the other websites are indexed.

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
    Deleting a record removes it from the queue and from the index. The
    saved record itself is only queued by indexing services of its own
    site, see :ref:`configuration-search-engine`. Saving an enabled page
    also queues its content elements for every content element indexing
    service that covers them, whatever site the service belongs to. Saving
    a hidden page, or one with :guilabel:`Include in Search` disabled,
    removes its content elements from the queue and the index instead.
*   Saving or deleting a category queues the records assigned to it, and
    those assigned to its subcategories, again, because an indexed document
    can carry values derived from its categories. A record whose assignment
    the save removed is queued as well. The records stay in the index and
    searchable until the worker has indexed them again. Changes made in a
    workspace are not considered. An extension can add records that reference
    a category in another way, for example through a plain column, by
    listening to the event
    ``MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent``.
*   The :guilabel:`Queue` backend module queues all records of the
    selected indexing services. It skips hidden subpages of the pages
    selected in :guilabel:`Pages (recursively)`, together with their
    subpages and the records on them.
*   The console command ``mkk:queue:index:worker``, usually run as a
    scheduler task, processes the queue and sends the records to Algolia.
    A record that Algolia rejects as too big is logged as a warning and
    removed from the queue without being indexed, so that it does not fail
    again on every run.
