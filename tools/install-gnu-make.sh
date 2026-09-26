#!/bin/sh
set -eu

prefix=${1:?Usage: install-gnu-make.sh ABSOLUTE_PREFIX}
case "$prefix" in
    /*) ;;
    *) echo 'The installation prefix must be absolute.' >&2; exit 1 ;;
esac
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
version=$(cat "$root/GNU_MAKE_VERSION")
# Update the archive pin deliberately when changing the compatibility target.
test "$version" = 4.4.1
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
curl -fsSL "https://ftp.gnu.org/gnu/make/make-$version.tar.gz" -o "$work/make.tar.gz"
(cd "$work" && echo 'dd16fb1d67bfab79a72f5e8390735c49e3e8e70b4945a15ab1f81ddb78658fb3  make.tar.gz' | sha256sum -c -)
tar -xzf "$work/make.tar.gz" -C "$work"
cd "$work/make-$version"
./configure --prefix="$prefix" --disable-nls --without-guile
make -j2
make install
"$prefix/bin/make" --version | grep -Fx "GNU Make $version"
