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
    Deleting a record removes it from the queue and from the index once the
    record is really deleted. A delete that is refused changes nothing. An
    enabled record that no indexing service accepts any longer, for example
    a page of a page type that is not indexed or a file that was moved out
    of the indexed folder, is removed from the index when it is saved or
    moved. The same happens when a file is queued by hand from its context
    menu. That entry is offered for files that at least one file indexing
    service accepts and whose metadata the user may edit. Queueing such a
    file removes its document from every index in which no consulted file
    indexing service accepts the file.

    The removal is decided for each search index separately. The consulted
    services are the indexing services of the record's own site and, for
    files, every file indexing service. The document is only removed from an
    index when none of the consulted services that write to that index
    accepts the record. A service of another site is not consulted, so the
    document is kept when such a service writes to the same index, and a
    warning is logged. The former site of a moved record is the exception,
    see below.

    A file is only treated as no longer accepted when the allowed file
    extensions are configured. Otherwise its document is kept and a warning
    is logged. A file whose extension is no longer in the allowed list is
    rejected like any other file, so renaming or replacing a file with one
    of another extension removes its document. A file indexing service
    without a file collection, or with hidden ones only, rejects every file.
    If it is the only service that writes to its index and the extensions
    are configured, saving a file with an allowed extension removes its
    document. A failure while removing the document of a rejected record is
    logged as an error and does not abort the save. An enabled record that
    the backend creates and a file that is uploaded or copied under a new
    name normally have no document yet, so nothing is removed for them. A file that
    is replaced, through an upload with the conflict mode replace or with
    the replace function of the file list, keeps its metadata record and
    goes through the check.

    Deleting a page also removes its subpages, the content elements on all
    of them, the translations of the pages and the records of other indexed
    tables that are stored on them, for example news, from the index. This
    includes hidden content elements and ones outside of their start and
    end time, and the subtree below recycler and backend user section
    pages. The indexing services are resolved before the deletion, so the
    documents are removed as well when the indexing service records are
    stored in the deleted tree. The documents are removed with the
    indexing services of the site of the deleted page and of every site
    root below it. A record that DataHandler leaves behind below the
    deleted page, for example a content element the user may not edit,
    keeps its document.

    Publishing a delete or a move from a workspace is handled like the live
    command. The publish deletes or moves the live record through the
    version command, so the documents are removed or decided for the
    parent that the live record has after the publish. This needs the
    workspaces extension, see :ref:`installation-workspaces`.

    Deleting the translation of a page removes the records of that language
    that are stored on the page of the default language. Deleting a record
    also removes its translations and the child records of its inline
    relations that store the children through a foreign field and have no
    foreign table field. An example is the items of a content element.
    Their translations are removed too, as far as they are deleted with the
    record. A relation whose configuration keeps
    its children when the parent is deleted is not followed by DataHandler,
    so the documents of such children stay. Children that the parent
    stores as a list in a field of its own are not looked at.
    Deleting a file in the file list removes the document of the file. An
    error that the search engine raises is logged and does not abort the
    deletion. The documents are removed in blocks, and a block that fails
    does not stop the next one.

    A search engine that fails on consecutive blocks ends that removal, and
    what was not sent is logged. An unreachable search engine therefore costs
    the request of the editor a few failed removal calls for each table and
    indexing service of the deletion, and not one for every block. The
    removal of a document that failed or was not sent is not retried by
    the same deletion. The document of a deleted record stays in the index
    until the index is cleared and built again. The document of a record that
    still exists is removed by the next save or move that rejects the record.

    A record with a start time in the future or an end time in the past, for
    example a page or content element, is not accepted by the indexer, so
    its document is removed when it is saved. It is not queued again when
    the start time is reached. Save it again or queue the records anew
    after the start time.

    A save that leaves a page enabled removes the document of the saved
    record itself and not the ones of the subpages and content elements
    that it queues along with it. A save that hides a page removes the
    content elements of that page. Its subpages are decided only when the
    page has :guilabel:`Extend to Subpages` enabled after the save, in which
    case their documents are removed, or when the same save switches it off,
    in which case they are queued again. The move
    of a page decides its subpages and their content elements, see below.
    The translations and workspace versions of file metadata are not
    decided, because their own save leaves the document of the live
    record.

    A page or content element that is moved to another site is also checked
    against the indexing services of its former site, so its document is
    removed from the index of that site, unless a service of either site
    that writes to the same index still accepts the record.

    The subpages of a moved page and their content elements are decided
    the same way, also when the page stays in its site and only leaves the
    selected page tree of a service. The records that are still accepted
    are queued again. A site root below the moved page keeps its own site
    and is left out together with everything below it, and a site root that
    is moved does not change the site of its tree. A page that is hidden or
    not published at the moment and extends that state to its subpages is
    decided itself, but what lies below it is not, and neither are the
    subpages of a moved page that is in that state. The page that a content element was
    moved away from is queued again by the page indexing services of its
    own site that include content elements, and so is the page that
    received the element. The translations of a page are not decided. The
    page walk of an indexing service covers the default language only, and
    a page indexing service without a page selection accepts translations
    as well, which a move does not decide. The translations of a moved
    content element are decided with the element. So are its inline child
    records of the same table, which are content elements, and their
    translations. This holds for the relations that store the children
    through a foreign field.

    The records of other indexed tables that are stored on a moved page
    are not decided again.

    The indexing services are resolved after the move. Whether a service
    that is stored inside the moved tree is taken for one of the former or
    one of the new site depends on cached rootlines, so the move of such a
    tree can leave documents of the former site in place. The configuration
    of the indexing service says how to avoid this, see
    :ref:`configuration-indexing-service`.

    The saved record itself is only queued by indexing services of its own
    site, see :ref:`configuration-search-engine`, except for files, which
    every file indexing service considers. Saving an enabled page also
    queues its content elements for every content element indexing service
    that covers them, whatever site the service belongs to. Saving a hidden
    page, one with :guilabel:`Include in Search` disabled or one that is
    outside of its publication window removes its content elements from the
    queue and the index instead. A content element on such a page is not
    queued either, because the page itself is not indexed. A visible page
    below a hidden page, or one outside of its publication window, is not
    affected, unless that page has :guilabel:`Extend to Subpages` enabled.
    The frontend hides its subpages as well, so their documents and the
    ones of their content elements are removed with it, and queued again
    when the page is shown again. Pages below a hidden subpage that does
    not extend its state are skipped like in the :guilabel:`Queue` module
    and are not queued again.
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
