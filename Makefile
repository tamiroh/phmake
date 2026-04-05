MAGO = vendor/bin/mago
PHPSTAN = vendor/bin/phpstan
PHPUNIT = vendor/bin/phpunit

.PHONY: test
test:
	$(PHPUNIT) tests

.PHONY: lint
lint:
	$(PHPSTAN) analyse src tests --memory-limit=2G

.PHONY: format
format:
	$(MAGO) fmt

.PHONY: format-check
format-check:
	$(MAGO) fmt --dry-run

.PHONY: check
check: lint format-check test
