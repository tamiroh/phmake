#!/bin/sh
set -eu

cd /opt/make
make
./make --version
./make --version | grep -Fx 'GNU Make 4.4.1'

smoke_dir=$(mktemp -d)
trap 'rm -rf "$smoke_dir"' EXIT
cat > "$smoke_dir/Makefile" <<'MAKEFILE'
.PHONY: all
all: output

output: input
	cp $< $@
	printf 'built\n' >> builds
MAKEFILE
printf 'GNU make build smoke test\n' > "$smoke_dir/input"

/opt/make/make -s -C "$smoke_dir"
cmp "$smoke_dir/input" "$smoke_dir/output"
/opt/make/make -s -C "$smoke_dir"
test "$(cat "$smoke_dir/builds")" = built

echo 'GNU make build smoke test passed'
