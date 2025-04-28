# News Indexer

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
