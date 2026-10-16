#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
version=$(cat "$root/GNU_MAKE_VERSION")
destination="$root/.local/references/gnu-make"

if [ -e "$destination" ]; then
    if [ ! -e "$destination/.git" ]; then
        echo "Destination exists but is not a Git checkout: $destination" >&2
        exit 1
    fi
    echo "Keeping existing checkout unchanged: $destination"
    git -C "$destination" log -1 --format='%h %s'
    exit 0
fi

mkdir -p "$(dirname -- "$destination")"
git clone --branch "$version" https://git.savannah.gnu.org/git/make.git "$destination"
