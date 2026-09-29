#!/bin/sh
set -eu

archive=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")
version=${2:-development}
sandbox=$(mktemp -d)
trap 'rm -rf "$sandbox"' EXIT HUP INT TERM

# Run away from the checkout, including a path containing spaces.
mkdir "$sandbox/standalone test"
cp "$archive" "$sandbox/standalone test/phmake.phar"
cd "$sandbox/standalone test"
chmod +x phmake.phar
./phmake.phar --version > version.txt
grep -Fx "phmake ($version)" version.txt
php phmake.phar --version > php-version.txt
cmp version.txt php-version.txt

cat > Makefile <<'MAKE'
.PHONY: all child
all: result.txt
	+@$(MAKE) --no-print-directory child
result.txt:
	@printf 'built\n' > $@
child:
	@test -f result.txt
	@printf 'recursive\n' > child.txt
MAKE
./phmake.phar --no-print-directory -j2
test "$(cat result.txt)" = built
test "$(cat child.txt)" = recursive

# Rebuilding an included makefile must restart the packaged executable.
cat > Makefile <<'MAKE'
include generated.mk
.PHONY: all
all:
	@test "$(GENERATED)" = yes
	@test "$(MAKE_RESTARTS)" = 1
generated.mk:
	@printf 'GENERATED = yes\n' > $@
MAKE
./phmake.phar --no-print-directory all
