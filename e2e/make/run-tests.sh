#!/bin/sh
set -eu

cd /opt/make/tests
if perl ./run_make_tests.pl -make /opt/phmake/phmake -srcdir /opt/make -phmake -keep "$@"; then
    status=0
else
    status=$?
fi

if [ -n "${PHMAKE_TEST_RESULTS:-}" ]; then
    mkdir -p "$PHMAKE_TEST_RESULTS"
    cp -R work "$PHMAKE_TEST_RESULTS/"
fi

exit "$status"
