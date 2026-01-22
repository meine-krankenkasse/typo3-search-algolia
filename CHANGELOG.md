# 1.2.1

## MISC

- dce62c1 WEB-1394: Use PHP84 polyfill for array_any

## Contributors

- Rico Sonntag

# 1.2.0

## MISC

- 95b0253 WEB-1394: Avoid duplicate code
- e28d190 WEB-1394: Rework file indexing
- 58cad5c WEB-1394: Apply rector rules
- 0944581 WEB-1394: Add detail view of enqueued items
- 769dc3d WEB-1394: Fix context menu queue provider to respect fileindexer configuration
- 8264fdf Correct file indexer icon
- 788802e Update dev tool configuration

## Contributors

- Rico Sonntag

# 1.1.0

## MISC

- a9e256c WEB-1366: Update subpages only if parent pages gets hidden and child pages should be processed "extendToSubpages"
- 4ddce00 WEB-1366: Add workspace handling

## Contributors

- Rico Sonntag

# 1.0.2

## MISC

- 1e33ca7 Remove deprecated variable "PHP_CS_FIXER_IGNORE_ENV"
- 8512977 Fix typo

## Contributors

- Rico Sonntag

# 1.0.1

## MISC

- 436635a Add identifiers to each event listener
- e3873c4 Update CategoryRepository to return whole record instead of just uid and title

## Contributors

- Rico Sonntag

# 1.0.0

## MISC

- 415ee44 Fix Rector complaining about the use of Stringable
- 62be7be Make DocumentBuilder skip unsupported field records
- 0f89dfd Add page categories
- 4d30480 Move querying field mapping into separate method
- 780f133 Add fractor dev tool
- 9e149f1 Update README
- ec4f254 Improve exception handling and possible null pointer
- 8ca4668 Update documentation
- 4c754c9 Add configuration for indexable content elements
- 4e1aa0c Prevent "json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded"
- 17eeea9 Handle disable/enable of pages with their subpages
- 6401d36 Exclude hidden pages (incl. subpages) if putting all pages into queue
- 114a538 Do not index images and ignore encrypted content in PDF
- f0e7adc Move record event listeners
- 37a91bb Move TypoScript related methods into single class to avoid duplicate code
- d5dfe0d Update README
- 90b0a43 Update language labels
- 3e436fb Add PHP requirement
- 50fc811 WEB-1242: Add PDF parsing
- 10087d0 WEB-1242: Handle file operations with queue and index
- 5749c1f WEB-1242: Add base file indexer
- 93de5b1 WEB-1243: Handle record undelete
- fa9a9c3 WEB-1243: Handle record movement
- 50f72f9 WEB-1243: Add button the clear queue entries from backend
- 65beb74 WEB-1243: Consolidate update/delete handling
- 55908cd WEB-1243: Handle r#ecord deletion (CE) and page updates
- 3d1d0d7 Check for valid search engine configuration
- 80e4a14 WEB-1243: Process content elements
- c786d85 WEB-1243: Handle record deletion (pages)
- 6601f74 WEB-1243: Replace method to query all page IDs recursively
- ead7119 WEB-1243: CRUD processing of record
- 4b15cd0 Add possibility to clear an index in admin module
- a405ba3 Add button to directly create a new indexing service
- 67990f8 Prevent exception if database is not set up correctly
- 1480383 Filter pages by doktype
- fe99362 Rework internal classes
- 9ae4ad5 WEB-1239: Indexing
- c3acde3 WEB-1239: Move methods in repository
- fb2b33a WEB-1239: Rework indexer registry, improve scheduler task
- cf97b3c WEB-1239: Add some queue statistics
- 531b5bf WEB-1239: Add base module structure
- 8ace781 WEB-1237: Use extbase controllers and routes
- e03e104 WEB-1237: Add admin/queue backend modules
- 6900894 WEB-1235: Add "algolia/algoliasearch-client-php" package
- 4c6650b WEB-1237: Add base backend module structure
- c8e9a0d WEB-1236: Add github CI configuration
- 4d1f176 WEB-1237: Update README
- 0410286 WEB-1237: Update .gitignore
- abdbb10 WEB-1237: Create base extension structure
- 6de8769 WEB-1236: Add dev tools
- ea45331 WEB-1235: Add base composer.json
- 991574d Initial commit

## Contributors

- Cry0nicS
- Rico Sonntag

