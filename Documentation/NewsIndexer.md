# News Indexer

The News Indexer is a specialized component of the TYPO3 Search Algolia extension that indexes news articles from the TYPO3 news extension (ext:news). This enables users to search for news content through your Algolia-powered search, making your news archive more accessible and useful.

## How It Works

The News Indexer processes news articles based on the configuration in your indexing service. When triggered, it:

1. Retrieves news articles from the TYPO3 database
2. Extracts relevant data from each news article, including title, teaser, content, and metadata
3. Creates individual search records for each news article
4. Sends the processed data to Algolia for indexing

This integration allows your site visitors to find relevant news articles quickly through search, improving the discoverability of your news content.

## Configuration

To use the News Indexer, create a new indexing service record and select "News" as the indexer type. For detailed instructions on setting up an indexing service, see the [Configuration](Configuration.md) documentation.

## News Properties

By default, all news articles will be indexed. If you want to exclude specific news articles from indexing, you can implement this through a custom extension of the News Indexer.

## Indexed Fields

### Custom Fields

Additional fields to be indexed from the news properties can be defined using the TypoScript configuration
`module.tx_typo3searchalgolia.indexer.tx_news_domain_model_news.fields`. This is set by default as follows:

```typo3_typoscript
module {
    tx_typo3searchalgolia {
        indexer {
            tx_news_domain_model_news {
                fields {
                    title = title
                    abstract = teaser
                    bodytext = description
                    author = author
                    author_email = authorEmail
                    keywords = keywords
                }
            }
        }
    }
}
```

## Usage and Best Practices

### Triggering News Indexing

News articles are indexed when:
- They are created or updated
- The indexing is triggered through the backend module
- A scheduled task runs the indexing process

### Best Practices

1. **News Record Quality**: Ensure your news articles have proper titles, teasers, and content. Well-written news articles with descriptive titles and comprehensive content will yield better search results.

2. **Metadata Usage**: Make good use of the author, keywords, and categories fields in your news records. These fields can enhance search relevance and enable filtering in search results.

3. **News Archive Structure**: Consider how your news categories and archive structure should be reflected in search results. You might want to include category information in the indexed data for better context.

4. **Pagination Handling**: For news archives with many articles, consider implementing pagination in your search results to handle large result sets efficiently.

5. **Date-Based Relevance**: Consider configuring your search to factor in the publication date of news articles, potentially giving more weight to newer articles in search results.

6. **Related Content**: If your news articles relate to other content on your site, consider how to link between search results for news and other content types.
