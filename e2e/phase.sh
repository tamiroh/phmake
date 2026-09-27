#!/bin/sh
set -eu

# Run one build step and report its elapsed time for compare-builds.py.
name=${1:?Usage: phase NAME COMMAND [ARGUMENT...]}
shift
started=$(date +%s.%N)
status=0
"$@" || status=$?
finished=$(date +%s.%N)
echo "@@phase $name $(awk -v started="$started" -v finished="$finished" 'BEGIN { printf "%.2f", finished - started }') $status"
exit "$status"
