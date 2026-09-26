#!/bin/sh
set -eu

# Match GNU make 4.4.1's check-regression resource limit.
ulimit -n 128

test_make=/opt/phmake/phmake
identity=-phmake
if [ "${1:-}" = --reference ]; then
    test_make=/usr/local/libexec/gnu-make-4.4.1
    identity=
    shift
fi

suite_dir=$(mktemp -d)
trap 'rm -rf "$suite_dir"' EXIT
cp -R /opt/make/tests/. "$suite_dir/"
cd "$suite_dir"
rm -rf work
# Without -keep, upstream removes generated targets while retaining failed cases.
if perl ./run_make_tests.pl -make "$test_make" -srcdir /opt/make $identity "$@"; then
    status=0
else
    status=$?
fi

if [ -n "${PHMAKE_TEST_RESULTS:-}" ]; then
    mkdir -p "$PHMAKE_TEST_RESULTS"
    cp -R work "$PHMAKE_TEST_RESULTS/"
fi

exit "$status"
