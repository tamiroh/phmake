#!/bin/sh
set -eu
printf 'compiler: %s\n' "$*"
while [ "$#" -gt 0 ]; do
    if [ "$1" = '-o' ]; then
        shift
        touch "$1"
        exit 0
    fi
    shift
done
exit 1
