# Requirements

## System Requirements
- TYPO3 v12.4, PHP >=8.3 and <8.5


# Installation
The extension should be installed via composer:

```shell
composer require meine-krankenkasse/typo3-search-algolia
```


# Uninstallation
```shell
composer remove meine-krankenkasse/typo3-search-algolia
```


# Testing
```shell
composer install    

composer ci:cgl
composer ci:test
composer ci:test:php:phplint
composer ci:test:php:phpstan
composer ci:test:php:rector
```
