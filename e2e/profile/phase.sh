#!/bin/sh
set -eu

# Replace e2e/phase.sh in profiling images. The clean build runs with GNU make
# because only phmake's rebuild phases are profiled.
name=${1:?Usage: phase NAME COMMAND [ARGUMENT...]}
if [ "$name" != clean-build ]; then
    mkdir -p "/profiles/$name"
    PHMAKE_PROFILE=/profiles/$name exec timed-phase "$@"
fi
phmake=$(readlink /usr/local/bin/make)
ln -sf /usr/bin/make /usr/local/bin/make
status=0
timed-phase "$@" || status=$?
ln -sf "$phmake" /usr/local/bin/make
exit "$status"
