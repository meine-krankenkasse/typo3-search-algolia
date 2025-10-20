.PHONY: help install lint format typecheck test ci clean
.DEFAULT_GOAL := help

help: ## Show available targets
	@awk 'BEGIN{FS=":.*##";print "\nUsage: make <target>\n"} /^[a-zA-Z0-9_.-]+:.*##/ {printf "  %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install dependencies
	composer install

lint: ## Run code style checks
	composer ci:test:php:lint
	composer ci:test:php:cgl

format: ## Fix code style issues
	composer ci:cgl

typecheck: ## Run static analysis
	composer ci:test:php:phpstan

test: ## Run all quality checks
	composer ci:test

ci: test ## Run CI pipeline locally

rector: ## Apply Rector refactoring
	composer ci:rector

fractor: ## Apply Fractor TYPO3 improvements
	composer ci:fractor

clean: ## Clean build artifacts
	rm -rf .build/
