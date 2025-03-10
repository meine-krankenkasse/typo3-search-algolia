[![Latest version](https://img.shields.io/github/v/release/meine-krankenkasse/typo3-search-algolia?sort=semver)](https://github.com/meine-krankenkasse/typo3-search-algolia/releases/latest)
[![License](https://img.shields.io/github/license/meine-krankenkasse/typo3-search-algolia)](https://github.com/meine-krankenkasse/typo3-search-algolia/blob/main/LICENSE)
[![CI](https://github.com/meine-krankenkasse/typo3-search-algolia/actions/workflows/ci.yml/badge.svg)](https://github.com/meine-krankenkasse/typo3-search-algolia/actions/workflows/ci.yml)


# typo3-search-algolia
A TYPO3 extension that integrates Algolia search into your website by indexing TYPO3 content for lightning-fast, 
relevant search results.


## Installation
The extension should be installed via composer:

``composer require meine-krankenkasse/typo3-search-algolia``


## Setup
### Update database structure
Use the "Analyze Database Structure" in the "Maintenance" Admin Tools section to update the database structure.

### Webservice

#### API endpoint
To access the Algolia API, store the corresponding configuration in the file `additional.php` within the
global structure "TYPO3_CONF_VARS" under "EXTENSIONS" and "typo3_search_algolia" (note the spelling) of your TYPO3 installation.

```php

// The universal messenger API endpoint
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_search_algolia'] = array_merge(
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_search_algolia'] ?? [],
    [
        'appId'  => 'YOUR-APP-ID',
        'apiKey' => 'YOUR-API-KEY',
    ]
);
```

| Field  | Description                                                                                                                                                      |
|:-------|:-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| appId  | Your Algolia application ID.                                                                                                                                     |
| apiKey | An API key with the necessary permissions to make the request. The required access control list (ACL) to make a request is listed in each endpoint's reference.  |

You can find your application ID and API key in the Algolia dashboard.


## Indexing
### Pages
#### Page properties
Indexing depends on page properties. Depending on the state of page properties the pages and
or sub pages may be indexed or not.

- Include in Search (no_search)
  - By default, every allowed page is indexed. Use this flag to exclude the current page from being indexed.
   