# Content Element Indexer

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
