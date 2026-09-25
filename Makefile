MAGO = vendor/bin/mago
PHPSTAN = vendor/bin/phpstan
PHPUNIT = vendor/bin/phpunit
PHP_CS_FIXER = vendor/bin/php-cs-fixer

.PHONY: test
test:
	$(PHPUNIT) tests

.PHONY: lint
lint:
	$(PHPSTAN) analyse --memory-limit=2G
	$(MAGO) --colors always lint
	$(MAGO) --colors always analyze
	$(MAGO) --colors always guard
	$(PHP_CS_FIXER) fix --dry-run --diff --using-cache=no --sequential

.PHONY: format
format:
	$(MAGO) --colors always fmt

.PHONY: format-check
format-check:
	$(MAGO) --colors always fmt --dry-run

.PHONY: check
check: lint format-check test
