[![Latest version](https://img.shields.io/github/v/release/meine-krankenkasse/typo3-search-algolia?sort=semver)](https://github.com/meine-krankenkasse/typo3-search-algolia/releases/latest)
[![License](https://img.shields.io/github/license/meine-krankenkasse/typo3-search-algolia)](https://github.com/meine-krankenkasse/typo3-search-algolia/blob/main/LICENSE)
[![CI](https://github.com/meine-krankenkasse/typo3-search-algolia/actions/workflows/ci.yml/badge.svg)](https://github.com/meine-krankenkasse/typo3-search-algolia/actions/workflows/ci.yml)
[![TER](https://img.shields.io/badge/TER-typo3__search__algolia-orange)](https://extensions.typo3.org/extension/typo3_search_algolia)


# typo3-search-algolia
A TYPO3 extension that integrates Algolia search into your website by indexing TYPO3 content for lightning-fast, 
relevant search results.

## Features
- Seamless integration with TYPO3 CMS
- Indexing of various content types:
  - Pages
  - Content elements
  - News articles
  - Files (including PDF content extraction)
- Configurable indexing services
- Field mapping via TypoScript
- Backend module for managing indexing
- Context menu integration for direct indexing
- Support for excluding specific content from search

## Requirements
- TYPO3 v14.3
- PHP >=8.3 and <8.6
- Algolia account with API credentials

## Versions

Each major version supports one TYPO3 major version. All of them are available in the
[TYPO3 Extension Repository (TER)](https://extensions.typo3.org/extension/typo3_search_algolia) and on
[Packagist](https://packagist.org/packages/meine-krankenkasse/typo3-search-algolia).

| Extension version | TYPO3 version | Branch     |
|-------------------|---------------|------------|
| 3.x               | 14.3          | `main`     |
| 2.x               | 13.4          | `TYPO3-13` |
| 1.x               | 12.4          | `TYPO3-12` |

## Quick Start
1. Install the extension via composer: `composer require meine-krankenkasse/typo3-search-algolia`
2. Configure your Algolia API credentials in `additional.php`
3. Create a data folder for your search configuration
4. Set up a search engine and indexing services
5. Start indexing your content

## Documentation

The documentation sources live in [Documentation/](Documentation/) and are rendered on
[docs.typo3.org](https://docs.typo3.org/p/meine-krankenkasse/typo3-search-algolia/main/en-us/).

## Optional Features

### Workspace Support
To enable automatic reindexing when publishing workspace records, install the workspaces extension:

```bash
composer require typo3/cms-workspaces
```

Without this extension, the search indexer will still work but won't automatically queue records when publishing from workspaces.

## Releasing

1. Bump the `version` in `ext_emconf.php`
2. Add a `CHANGELOG.md` entry (`# X.Y.Z` heading)
3. Commit and push
4. Create and push a tag matching the version, no `v` prefix, e.g.:
   ```bash
   git tag -s 1.3.3 -m 1.3.3
   git push origin 1.3.3
   ```
5. Pushing the tag publishes the new version to [TER](https://extensions.typo3.org/extension/typo3_search_algolia) and creates the GitHub Release

To re-publish an already-tagged version (e.g. to fix the TER upload comment), trigger the "Publish new extension version to TER" workflow manually via `workflow_dispatch` with that tag as input.
