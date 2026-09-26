# Git build verification

This CI job builds and installs Git 2.55.0 with GNU make and phmake on Linux. The release
archive and container base images are pinned by checksum. Recursive make calls
use the selected implementation. Tcl/Tk GUIs are disabled; the command-line tools (including Rust
code), Perl modules, templates, and translations use the normal `all` and
`install` targets.
PHP's memory limit is set to 1 GiB in this container because Git expands a large
Coccinelle rule matrix while reading its Makefile.

The installed Git must report the expected version and successfully initialize
a repository, commit a file, clone it through the local Git transport, and check
both repositories with `git fsck --strict`. The container runs without network
access during verification. This is a build smoke test, not Git's full test suite.

Run the same verification locally from the repository root:

```sh
docker build --platform linux/amd64 -f e2e/git/Dockerfile -t phmake-git-build .
python3 e2e/compare-builds.py git
```

See [build comparisons](../README.md) for timing details and single-implementation runs.
