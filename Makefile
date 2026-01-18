.PHONY: test
test:
	vendor/bin/phpunit tests

.PHONY: lint
lint:
	vendor/bin/phpstan analyse src tests --memory-limit=2G

.PHONY: format
format:
	vendor/bin/mago fmt

.PHONY: format-check
format-check:
	vendor/bin/mago fmt --dry-run

.PHONY: check
check: lint format-check test