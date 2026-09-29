MAGO = vendor/bin/mago
PHPSTAN = vendor/bin/phpstan
PHPUNIT = vendor/bin/phpunit
PHP_CS_FIXER = vendor/bin/php-cs-fixer
PHP = php
PHAR_INTERPRETER = env
VERSION = development

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
	$(PHP_CS_FIXER) fix --using-cache=no --sequential
	$(MAGO) --colors always fmt

.PHONY: format-check
format-check:
	$(MAGO) --colors always fmt --dry-run

.PHONY: check
check: lint format-check test

# Track command-line build settings as well as files in the archive.
PHAR_CONFIG = dist/.phar-config-$(shell printf '%s\n' "$(PHP)" "$(PHAR_INTERPRETER)" "$(VERSION)" | shasum -a 256 | cut -d ' ' -f 1)

.PHONY: phar
phar: dist/phmake.phar.sha256

dist:
	mkdir -p $@

$(PHAR_CONFIG): | dist
	rm -f dist/.phar-config-*
	touch $@

dist/phmake.phar: tools/build-phar.php Makefile LICENSE composer.json composer.lock $(shell find src vendor -type f -o -type d) $(PHAR_CONFIG) | dist
	rm -f $@.tmp.phar
	$(PHP) -d phar.readonly=0 tools/build-phar.php $@.tmp.phar "$(PHAR_INTERPRETER)" "$(VERSION)"
	mv $@.tmp.phar $@

dist/phmake.phar.sha256: dist/phmake.phar
	$(PHP) -r 'echo hash_file("sha256", "dist/phmake.phar"), "  phmake.phar\n";' > $@.tmp
	mv $@.tmp $@

.PHONY: test-phar
test-phar: phar
	sh tools/test-phar.sh "$(CURDIR)/dist/phmake.phar" "$(VERSION)"
