#!/bin/sh
set -eu

cd /opt/git
php /opt/phmake/phmake -j2 NO_TCLTK=YesPlease prefix=/opt/git-install all
php /opt/phmake/phmake -j2 NO_TCLTK=YesPlease prefix=/opt/git-install install

PATH=/opt/git-install/bin:$PATH
export PATH
test "$(command -v git)" = /opt/git-install/bin/git
git --version | grep -Fx 'git version 2.55.0'

smoke_dir=$(mktemp -d)
trap 'rm -rf "$smoke_dir"' EXIT
git init --initial-branch=main "$smoke_dir/source"
cd "$smoke_dir/source"
git config user.name example-user
git config user.email example-user@example.com
printf 'Git build smoke test\n' > sample.txt
git add sample.txt
git commit -m 'Add sample file'
git fsck --strict

git clone --no-local . "$smoke_dir/clone"
cmp sample.txt "$smoke_dir/clone/sample.txt"
test "$(git rev-parse HEAD)" = "$(git -C "$smoke_dir/clone" rev-parse HEAD)"
test -z "$(git -C "$smoke_dir/clone" status --porcelain)"
git -C "$smoke_dir/clone" fsck --strict

echo 'Git build smoke test passed'
