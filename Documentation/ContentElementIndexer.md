# Content Element Indexer

The Content Element Indexer is a specialized component of the TYPO3 Search Algolia extension that focuses on indexing individual content elements from TYPO3 pages. This allows for more granular search results, enabling users to find specific content elements rather than just pages.

## How It Works

The Content Element Indexer processes content elements based on the configuration in your indexing service. When triggered, it:

1. Retrieves content elements from the pages specified in your configuration
2. Extracts relevant data from each content element, including headers, text content, and other configured fields
3. Creates individual search records for each content element
4. Sends the processed data to Algolia for indexing

This approach is particularly useful for websites with large pages containing multiple content elements, as it allows for more precise search results.

## Indexed Fields

### Standard Fields

In addition to the [standard fields](Indexers.md#standard-indexed-fields), the following fields are indexed by default
for content elements:

| Field | Description                                                                       |
|-------|-----------------------------------------------------------------------------------|
| site  | The domain name of the page.                                                      |
| url   | The absolute URL to the content element on the page (only included if available). |

### Custom Fields

Additional fields to be indexed from the content element properties can be defined using the TypoScript configuration
`module.tx_typo3searchalgolia.indexer.tt_content.fields`. This is set by default as follows:

```typo3_typoscript
module {
    tx_typo3searchalgolia {
        indexer {
            tt_content {
                fields {
                    header = title
                    subheader = subTitle
                    bodytext = description
                }
            }
        }
    }
}
```

## Usage and Best Practices

### When to Use the Content Element Indexer

Consider using the Content Element Indexer when:
- Your pages contain many different content elements
- You want users to find specific content within pages
- You need more granular search results than page-level indexing provides
- Your pages are large and would exceed Algolia's record size limits if indexed as a whole

### Best Practices

1. **Content Element Types**: Consider which content element types should be indexed. Text-based elements like text, textpic, and textmedia are typically most relevant for search.

2. **Field Mapping**: Customize the field mapping to ensure the most important content from your elements is properly indexed. Different content element types may need different field mappings.

3. **URL Generation**: Ensure that the URL generation for content elements includes proper anchors to direct users to the specific element within a page.

4. **Complementary Indexing**: Consider using both Page Indexer and Content Element Indexer together - pages for general content and content elements for specific details.

5. **Performance Considerations**: Be mindful of the number of content elements being indexed, as this can significantly increase the size of your index and affect performance.
