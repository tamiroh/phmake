# phmake

Re-implementation of GNU Make written in PHP.

## Usage

```bash
./phmake [target]
```

Optional support for GNU make loadable modules and Guile uses the
[native module host](native/README.md). Makefile parsing, expansion, and build
execution remain in PHP. The [GNU make E2E suite](e2e/make/README.md) enables both
integrations in CI.
