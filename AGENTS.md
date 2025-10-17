<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2024-10-17 -->

# TYPO3 Search Algolia Extension

A TYPO3 extension that integrates Algolia search by indexing TYPO3 content for lightning-fast search results.

## Overview

This extension provides seamless Algolia integration for TYPO3 CMS with configurable indexing services for pages, content elements, news articles, and files.

## Setup & Environment

**Prerequisites:**
- TYPO3 v12.4
- PHP ≥8.3 and <8.5
- Algolia account with API credentials

**Installation:**
```bash
nrdev composer require meine-krankenkasse/typo3-search-algolia
```

**Configuration:**
1. Configure Algolia API credentials in `additional.php`
2. Create data folder for search configuration
3. Set up search engine and indexing services

## Build & Tests

**Install dependencies:**
```bash
nrdev composer install
```

**Code quality checks:**
```bash
nrdev composer ci:test           # Run all tests
nrdev composer ci:test:php:lint  # PHP lint
nrdev composer ci:test:php:phpstan # Static analysis
nrdev composer ci:test:php:cgl   # Code style check
```

**Code formatting:**
```bash
nrdev composer ci:cgl            # Fix code style
nrdev composer ci:rector         # Apply Rector fixes
nrdev composer ci:fractor        # Apply Fractor fixes
```

## Code Style

- **PSR-12** coding standard via PHP-CS-Fixer
- **PHPStan level 8** with strict rules
- **Rector** for automated refactoring
- **Fractor** for TYPO3-specific improvements

## Security

- No secrets in VCS - use TYPO3's configuration system
- API credentials via `additional.php`
- Input validation on all indexing operations
- Sanitized output in search results

## PR/Commit Checklist

- [ ] **Conventional Commits** format
- [ ] All tests pass (`nrdev composer ci:test`)
- [ ] Code style fixed (`nrdev composer ci:cgl`)
- [ ] PHPStan analysis clean
- [ ] Documentation updated if needed
- [ ] CHANGELOG.md updated for user-facing changes

## Examples

**Good:**
```php path=null start=null
<?php
declare(strict_types=1);

namespace MeineKrankenkasse\Typo3SearchAlgolia\Service;

use TYPO3\CMS\Core\SingletonInterface;

final class SearchService implements SingletonInterface
{
    public function __construct(
        private readonly AlgoliaClient $client
    ) {}
}
```

**Bad:**
```php path=null start=null
<?php
class SearchService {
    public $client; // No type hints, missing declare(strict_types=1)
}
```

## When Stuck

1. Check [Documentation/](Documentation/) folder for specific features
2. Verify TYPO3 compatibility in `composer.json`
3. Review Algolia client setup in configuration files
4. Check PHPStan baseline for known issues: `Build/phpstan-baseline.neon`
5. TYPO3 specific: Use `nrdev` prefix for all CLI commands

## House Rules

- **TYPO3 Coding Standards**: Follow TYPO3 CGL
- **Strict Types**: Always use `declare(strict_types=1)`
- **Dependency Injection**: Use TYPO3's DI container
- **Extbase/Fluid**: Follow TYPO3 MVC patterns
- **Database**: Use TYPO3's QueryBuilder, avoid raw SQL
- **Configuration**: Use TYPO3's configuration system (TypoScript, YAML)
- **Testing**: Focus on unit tests for business logic
- **Documentation**: Keep inline docs updated for complex indexing logic