.PHONY: test
test:
	cd tests && ./test.sh

.PHONY: lint
lint:
	vendor/bin/phpstan analyse src --memory-limit=2G