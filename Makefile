.PHONY: test
test:
	vendor/bin/phpunit tests

.PHONY: lint
lint:
	vendor/bin/phpstan analyse src tests --memory-limit=2G

.PHONY: format
format:
	vendor/bin/mago fmt

.PHONY: check
check: lint test