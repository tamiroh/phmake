#!/bin/sh
set -eu

cd /opt/llama.cpp-build
phase clean-build make -j2
phase no-op-rebuild make -j2
touch /opt/llama.cpp/tools/tokenize/tokenize.cpp
phase touched-rebuild make -j2

# The tokenizer tests compare bundled vocabularies with their expected tokens, without models or network.
ctest --output-on-failure -R '^test-tokenizer-0-' | tee /tmp/ctest.log
grep -Eq '^100% tests passed, 0 tests failed out of (1[0-9]|[2-9][0-9])$' /tmp/ctest.log

echo 'llama.cpp build smoke test passed'
