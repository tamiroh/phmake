# phmake

Re-implementation of GNU Make written in PHP.

See the [Makefile engine overview](src/Makefile/README.md) for its structure,
runtime flow, and a suggested reading order.

The [E2E build comparisons](e2e/README.md) run real projects with GNU make and
phmake, reporting correctness and elapsed time in CI.

## Usage

```bash
./phmake [target]
```
