# File Indexer

## File Properties

The "sys_file_metadata" table has been expanded to include the "no_search" field, which behaves identically to the
existing field in the "pages" table and, if set, allows a file to be excluded from indexing. By default, this field is
not set for all files, meaning they will be indexed.

To set the property, click on the desired file in the file list and switch to the new "Behavior" tab. Here you can
adjust the value for the "no_search" property accordingly.

![metadata-no-search](Images/FileIndexer-001.png)

*Fig. 1: Exclude file from indexing*

## Indexed Fields

### Standard Fields

In addition to the [standard fields](Indexers.md#standard-indexed-fields), the following fields are indexed by default
for files:

| Field     | Description                                                                           |
|-----------|---------------------------------------------------------------------------------------|
| extension | The file extension.                                                                   |
| mimeType  | The MIME type of the file.                                                            |
| name      | The name of the file.                                                                 |
| size      | The size of the file in bytes.                                                        |
| url       | The relative URL to the file (only included if available).                            |
| content   | The content of the file (only included for supported file types, currently only PDF). |

### Custom Fields

Additional fields to be indexed from the file metadata can be defined using the TypoScript configuration
module.tx_typo3searchalgolia.indexer.sys_file_metadata.fields. This is set by default as follows:

```typo3_typoscript
module {
    tx_typo3searchalgolia {
        indexer {
            sys_file_metadata {
                fields {
                    title = title
                    description = description
                    alternative = alternative
                    creator = author
                }

                # Comma-separated list of allowed file extensions
                extensions = pdf
            }
        }
    }
}
```

## Context menu

A single file can also be directly enqueued using the TYPO3 context menu in the file list:

![file-context-menu](Images/FileIndexer-002.png)

*Fig. 2: Directly enqueue a file*
