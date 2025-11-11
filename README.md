[![Latest version](https://img.shields.io/github/v/release/meine-krankenkasse/typo3-search-algolia?sort=semver)](https://github.com/meine-krankenkasse/typo3-search-algolia/releases/latest)
[![License](https://img.shields.io/github/license/meine-krankenkasse/typo3-search-algolia)](https://github.com/meine-krankenkasse/typo3-search-algolia/blob/main/LICENSE)
[![CI](https://github.com/meine-krankenkasse/typo3-search-algolia/actions/workflows/ci.yml/badge.svg)](https://github.com/meine-krankenkasse/typo3-search-algolia/actions/workflows/ci.yml)


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
- Customizable field mapping
- Backend module for managing indexing
- Context menu integration for direct indexing
- Support for excluding specific content from search

## Requirements
- TYPO3 v12.4
- PHP >=8.3 and <8.5
- Algolia account with API credentials

## Quick Start
1. Install the extension via composer: `composer require meine-krankenkasse/typo3-search-algolia`
2. Configure your Algolia API credentials in `additional.php`
3. Create a data folder for your search configuration
4. Set up a search engine and indexing services
5. Start indexing your content

## Table of contents
- [Requirements & Installation](Documentation/Requirements.md)
- [Configuration](Documentation/Configuration.md)
- [Indexers](Documentation/Indexers.md)
  - [Page Indexer](Documentation/PageIndexer.md)
  - [Content Element Indexer](Documentation/ContentElementIndexer.md)
  - [News Indexer](Documentation/NewsIndexer.md)
  - [File Indexer](Documentation/FileIndexer.md)

## Optional Features

### Workspace Support
To enable automatic reindexing when publishing workspace records, install the workspaces extension:

```bash
composer require typo3/cms-workspaces
```

Without this extension, the search indexer will still work but won't automatically queue records when publishing from workspaces.
