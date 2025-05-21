# Requirements & Installation

## System Requirements
- TYPO3 v12.4
- PHP >=8.3 and <8.5
- Composer
- Algolia account with API credentials

## Dependencies
- The extension requires access to the Algolia API for indexing and searching content
- For indexing news articles, the TYPO3 news extension (ext:news) must be installed

## Installation
The extension should be installed via composer:

```shell
composer require meine-krankenkasse/typo3-search-algolia
```

After installation:
1. Update the database structure using the "Analyze Database Structure" tool in the TYPO3 backend
2. Configure your Algolia API credentials (see [Configuration](Configuration.md))
3. Set up your indexing services and search engine (see [Configuration](Configuration.md))

## Uninstallation
To remove the extension from your TYPO3 installation:

```shell
composer remove meine-krankenkasse/typo3-search-algolia
```

After uninstallation, you may want to:
1. Remove any remaining database tables
2. Clean up any configuration in your `additional.php` file
3. Delete any indexed data from your Algolia account

## Development & Testing
The extension includes several tools for development and testing:

```shell
# Install dependencies
composer install    

# Check coding guidelines
composer ci:cgl

# Run all tests
composer ci:test

# Run specific tests
composer ci:test:php:phplint    # Check PHP syntax
composer ci:test:php:phpstan    # Static analysis
composer ci:test:php:rector     # Code quality checks
composer ci:test:php:fractor    # Code quality checks
```

These commands help ensure code quality and compatibility with TYPO3 coding standards.
