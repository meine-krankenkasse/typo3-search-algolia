# Indexers

Indexers are the core components of the TYPO3 Search Algolia extension that extract and process data from various TYPO3 content types for search indexing. They serve as the bridge between your TYPO3 content and the Algolia search service, ensuring that your content is properly structured and optimized for search.

## General

An indexer provides the necessary knowledge for processing the associated data, i.e., it knows exactly which data needs
to be read, processed, and indexed, how, and where. Processing can be customized and controlled via an additional
configuration (indexing service).

There can only ever be one indexer for a data type. However, there can be multiple indexing services that configure the
respective indexer.

To add additional indexers, see: [Custom Indexer](CustomIndexer.md)

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
