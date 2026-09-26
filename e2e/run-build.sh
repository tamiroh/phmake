#!/bin/sh
set -eu

project=${1:?Usage: run-build PROJECT}
case "$project" in
    php|lua|make|git|linux) ;;
    *) echo "Unknown build project: $project" >&2; exit 2 ;;
esac

implementation=${MAKE_IMPLEMENTATION:-phmake}
case "$implementation" in
    phmake) executable=/opt/phmake/phmake ;;
    gnu) executable=/usr/bin/make ;;
    *) echo "Unknown make implementation: $implementation" >&2; exit 2 ;;
esac

# Select both direct calls and recursive make invocations in this fresh container.
ln -sf "$executable" /usr/local/bin/make
echo "Building $project with $implementation"
make --version
exec sh "/usr/local/bin/verify-$project-build"
