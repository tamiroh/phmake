# phmake

Re-implementation of GNU Make written in PHP.

## Install

### Homebrew

```bash
brew install tamiroh/tap/phmake
```

### Manual

Requires PHP 8.5 or later.

```bash
curl -fL https://github.com/tamiroh/phmake/releases/latest/download/phmake.phar -o phmake
chmod +x phmake
sudo mv phmake /usr/local/bin/phmake
```

## Usage

```bash
phmake [target]
```

## GitHub Actions

To check your Makefile on pushes and pull requests, add this workflow to
`.github/workflows/makefile.yml`:

```yaml
name: Check Makefile
on: [push, pull_request]
permissions:
  contents: read

jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7.0.1
      - uses: tamiroh/phmake@main
```

The action checks `Makefile` without modifying it and fails if lint reports a
problem or formatting differs. To check another file, set `with.file` to its
path relative to the repository root.
