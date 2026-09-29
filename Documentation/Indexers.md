# Indexers

Indexers are the core components of the TYPO3 Search Algolia extension that extract and process data from various TYPO3 content types for search indexing. They serve as the bridge between your TYPO3 content and the Algolia search service, ensuring that your content is properly structured and optimized for search.

## General

An indexer provides the necessary knowledge for processing the associated data, i.e., it knows exactly which data needs
to be read, processed, and indexed, how, and where. Processing can be customized and controlled via an additional
configuration (indexing service).

There can only ever be one indexer for a data type. However, there can be multiple indexing services that configure the
respective indexer.

To add additional indexers, see: [Custom Indexer](CustomIndexer.md)

### Category changes

When a system category is saved, created or deleted in the live workspace, the records assigned to it or to one of its
subcategories through `sys_category_record_mm` (any MM fieldname) are put into the indexing queue again, through the
indexers responsible for them (those of their page tree, for files every file indexing service), so values derived from
the category end up in the index without editing each record. This includes records whose assignment the save itself
removes, e.g. in the category's "Items" tab. Records an indexer does not accept are skipped as usual. Like in a full
rebuild of the queue, pages below a hidden subpage of a recursively selected page tree are skipped too. Records the
indexer still accepts stay in the search index until the queue worker has indexed them again, records it no longer
accepts are not removed from the index. Publishing a category from a workspace or restoring a deleted one requeues the
records it is assigned to at that point, assignments removed inside a workspace are not covered.

Hidden or start/endtime-restricted subcategories and their subtrees are skipped. This assumes your documents carry a
category's title path only up to its first category that is not visible. If your documents also include hidden
ancestors, add those records through the event described below.

If your records reference categories in another way, e.g. through a plain integer column of a "select single" category
field, listen to `MeineKrankenkasse\Typo3SearchAlgolia\Event\CollectCategoryRecordsEvent` and add those records with
`addRecordUids()`:

```php
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
```

## Available Indexers
The following indexers are already implemented: 

- [Content Element Indexer](ContentElementIndexer.md)
- [File Indexer](FileIndexer.md)
- [News Indexer](NewsIndexer.md)
- [Page Indexer](PageIndexer.md)

### Standard Indexed Fields

Depending on the indexer, certain fields of a record are indexed. The following fields are common to all:

| Field   | Description                                                                       |
|---------|-----------------------------------------------------------------------------------|
| uid     | The UID of the record.                                                            |
| pid     | The parent ID of the record.                                                      |
| type    | The type of the indexed record. Correlates with the table name.                   |
| indexed | The timestamp at which indexing took place.                                       |
| created | The timestamp at which the record was created (only included if available).       |
| changed | The timestamp at which the record was last modified (only included if available). |
