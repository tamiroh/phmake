MAGO = vendor/bin/mago
PHPSTAN = vendor/bin/phpstan
PHPUNIT = vendor/bin/phpunit
PHP_CS_FIXER = vendor/bin/php-cs-fixer
PHP = php
BOX = .local/box-4.7.0.phar
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
PHAR_CONFIG = dist/.phar-config-$(shell printf '%s\n' "$(PHP)" "$(PHAR_INTERPRETER)" "$(VERSION)" "$(BOX)" | shasum -a 256 | cut -d ' ' -f 1)

.PHONY: phar
phar: dist/phmake.phar

dist:
	mkdir -p $@

$(PHAR_CONFIG): | dist
	rm -f dist/.phar-config-*
	touch $@

$(BOX):
	mkdir -p "$(@D)"
	curl -fsSL https://github.com/box-project/box/releases/download/4.7.0/box.phar -o "$@.tmp"
	printf '%s  %s\n' 3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52 "$@.tmp" | shasum -a 256 -c -
	mv "$@.tmp" "$@"

define box_configuration
$$config = json_decode(file_get_contents("box.json"), true, flags: JSON_THROW_ON_ERROR);
$$config["base-path"] = ".";
$$config["shebang"] = "#!" . ($$argv[2] === "env" ? "/usr/bin/env php" : $$argv[2]);
$$config["replacements"]["development"] = var_export($$argv[1], true);
echo json_encode($$config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
endef

dist/phmake.phar: export BOX_CONFIGURATION = $(box_configuration)
dist/phmake.phar: box.json phmake Makefile LICENSE composer.json composer.lock $(shell find src vendor -type f -o -type d) $(PHAR_CONFIG) $(BOX) | dist
	$(PHP) -r "$$BOX_CONFIGURATION" "$(VERSION)" "$(PHAR_INTERPRETER)" > dist/box.json
	$(PHP) -d phar.readonly=0 $(BOX) compile --config=dist/box.json --no-parallel --no-interaction
	mv $@.tmp.phar $@

.PHONY: test-phar
test-phar:
	sh tools/test-phar.sh "$(CURDIR)/dist/phmake.phar" "$(VERSION)"
