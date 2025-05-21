# Page Indexer

The Page Indexer is a core component of the TYPO3 Search Algolia extension that indexes TYPO3 pages for search. It extracts relevant information from pages, including titles, metadata, and optionally content elements, making them searchable through Algolia.

## How It Works

The Page Indexer processes pages based on the configuration in your indexing service. When triggered, it:

1. Retrieves pages according to your configuration (single pages or recursive page trees)
2. Filters pages based on their properties (page type, no_search flag, etc.)
3. Extracts relevant data from each page
4. Optionally includes content elements if configured
5. Sends the processed data to Algolia for indexing

## Page Properties

Indexing depends on the page properties. Depending on the status of the page properties, the pages and/or subpages can
be indexed or not.

To actively exclude a page from indexing, deactivate the "no_search" property in the page properties. This is located in
the "Behavior" tab by default.

![page-no-search](Images/PageIndexer-001.png)

*Fig. 1: Exclude page from indexing*

## Indexed Fields

### Standard Fields

In addition to the [standard fields](Indexers.md#standard-indexed-fields), the following fields are indexed by default
for pages:

| Field   | Description                                                                                                                                                                         |
|---------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| site    | The domain name of the page.                                                                                                                                                        |
| url     | The absolute URL to the page (only included if available).                                                                                                                          |
| content | The page content (only included if the content elements of a page are indexed). See also: [Configuration of the page indexing service](Configuration.md#page-indexer-configuration) |

### Custom fields

Additional fields to be indexed from the page properties can be defined using the TypoScript configuration
`module.tx_typo3searchalgolia.indexer.pages.fields`. This is set by default as follows:

```typo3_typoscript
module {
    tx_typo3searchalgolia {
        indexer {
            pages {
                fields {
                    title = title
                    subtitle = subTitle
                    nav_title = navTitle
                    description = description
                    abstract = teaser
                    author = author
                    keywords = keywords
                }
            }
        }
    }
}
```

## Usage and Best Practices

### Triggering Page Indexing

Pages are automatically indexed when:
- They are created or updated
- The indexing is triggered through the backend module
- A scheduled task runs the indexing process

### Best Practices

1. **Selective Indexing**: Only index pages that are relevant for search to keep your index size manageable.

2. **Page Types**: Consider which page types should be indexed. Typically, you'll want to index standard content pages but exclude special page types like folders, shortcuts, or backend modules.

3. **Content Elements**: Decide whether to include content elements directly in the page index. For sites with many content elements per page, it might be better to use a separate Content Element Indexer to avoid hitting Algolia's record size limits.

4. **Metadata**: Ensure your pages have proper metadata (titles, descriptions, keywords) for better search results.

5. **Hierarchical Structure**: Consider how your page hierarchy should be reflected in search results. You might want to include parent page information in child pages for context.
