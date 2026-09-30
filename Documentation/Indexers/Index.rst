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

..  note::
    When the console command ``mkk:queue:index:worker``, which sends the
    records to Algolia, runs on the command line (cron or the scheduler
    command), it has no site context. It reads the TypoScript of the
    first site root page of the page tree, not the TypoScript of the site
    a record belongs to. On a multi-site installation, define the mapping,
    the allowed file extensions and the options of the indexers in
    TypoScript that applies to all sites, for example in a site package
    that the root template of every site includes.

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
*   The :guilabel:`Queue` backend module queues all records of the
    selected indexing services. It skips hidden subpages of the pages
    selected in :guilabel:`Pages (recursively)`, together with their
    subpages and the records on them.
*   The console command ``mkk:queue:index:worker``, usually run as a
    scheduler task, processes the queue and sends the records to Algolia.
    A record that Algolia rejects because it is too big is logged as a
    warning and removed from the queue without being indexed, so it is not
    retried on every run.
*   Changes to a system category queue the records assigned to it again,
    see :ref:`indexers-category-changes`.

..  _indexers-category-changes:

Category changes
================

When a system category is saved, created or deleted in the live workspace,
the records assigned to it or to one of its subcategories through
``sys_category_record_mm`` are put into the indexing queue again. Every
``fieldname`` of the assignment counts. The records are queued through the
indexers responsible for them. These are the indexing services of the
page tree the record belongs to, and for files every file indexing service.
Values derived from the category therefore end up in the index without
editing each record.

The following rules apply:

*   Records whose assignment the save itself removes are queued as well,
    for example in the :guilabel:`Items` tab of the category.
*   Records that an indexer does not accept are skipped as usual.
*   Like in a full rebuild of the queue, pages below a hidden subpage of a
    recursively selected page tree are skipped too.
*   Records that the indexer still accepts stay in the search index until
    the queue worker has indexed them again. Records that it no longer
    accepts are not removed from the index.
*   Publishing a category from a workspace or restoring a deleted one
    queues the records it is assigned to at that point. Assignments that
    were removed inside a workspace are not covered.
*   Hidden or start and end time restricted subcategories are skipped
    together with their subtrees. This assumes that your documents carry
    the title path of a category only up to its first category that is not
    visible. If your documents also include hidden ancestors, add those
    records through the event described below.

If your records reference categories in another way, for example through a
plain integer column of a single select category field, listen to
``MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent``
and add those records with ``addRecordUids()``:

..  code-block:: php
    :caption: EXT:my_site_package/Classes/EventListener/AddReferencingPagesEventListener.php

    use MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent;

    final readonly class AddReferencingPagesEventListener
    {
        public function __invoke(CollectCategoryRecordsEvent $event): void
        {
            // Your own lookup of the pages referencing the category
            $pageUids = $this->pageRepository->findUidsReferencingCategory($event->getCategoryUid());

            $event->addRecordUids('pages', $pageUids);
        }
    }

Register the listener in the :file:`Configuration/Services.yaml` of your
extension:

..  code-block:: yaml
    :caption: EXT:my_site_package/Configuration/Services.yaml

    services:
        Vendor\MySitePackage\EventListener\AddReferencingPagesEventListener:
            tags:
                -   name: event.listener
                    identifier: 'my-site-package/add-referencing-pages'
                    event: MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent
