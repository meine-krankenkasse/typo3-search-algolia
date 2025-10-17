.PHONY: help install lint format typecheck test ci clean
.DEFAULT_GOAL := help

help: ## Show available targets
	@awk 'BEGIN{FS=":.*##";print "\nUsage: make <target>\n"} /^[a-zA-Z0-9_.-]+:.*##/ {printf "  %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install dependencies
	nrdev composer install

lint: ## Run code style checks
	nrdev composer ci:test:php:lint
	nrdev composer ci:test:php:cgl

format: ## Fix code style issues
	nrdev composer ci:cgl

typecheck: ## Run static analysis
	nrdev composer ci:test:php:phpstan

test: ## Run all quality checks
	nrdev composer ci:test

ci: test ## Run CI pipeline locally

rector: ## Apply Rector refactoring
	nrdev composer ci:rector

fractor: ## Apply Fractor TYPO3 improvements
	nrdev composer ci:fractor

clean: ## Clean build artifacts
	rm -rf .build/