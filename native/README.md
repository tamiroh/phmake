# Native module host

`module-host.c` provides the C ABI used by GNU make's `load` directive. It loads
shared libraries and exposes `gmk_alloc`, `gmk_free`, `gmk_add_function`,
`gmk_expand`, and `gmk_eval`. Expansion, argument handling, Makefile evaluation,
and build decisions remain in PHP. No GNU make executable or library is used.

The host is optional. On Linux, build it with:

```sh
cc -std=c11 -O2 -Wall -Wextra -Werror -Wl,--export-dynamic \
  -o /tmp/phmake-module-host native/module-host.c -ldl
PHMAKE_MODULE_HOST=/tmp/phmake-module-host php phmake
```

phmake also discovers `/usr/local/libexec/phmake-module-host`. Its `.FEATURES`
variable includes `load` only when an executable host is available. The GNU make
E2E image builds and installs the host at this location, so both upstream module
categories run in CI.

Each loaded module has a separate host process. Rebuilding a module closes its
host before executing the recipe, then reloads it afterward. A setup result of
`-1` keeps the module loaded and excludes it from automatic rebuilding. A changed
module file triggers Makefile reevaluation; an unchanged file whose rebuild
recipe ran is reloaded without repeating Makefile parsing.

The private pipe protocol uses a one-byte operation, a big-endian 32-bit field
count, and length-prefixed fields. Native expansion and evaluation callbacks can
make nested requests. Standard output and standard error are captured separately
and forwarded through phmake's output synchronization. The host exits when its
input closes; a crash or truncated response becomes a phmake error.

For optional Guile support, install the Guile 3.0 development package and add
`-DPHMAKE_GUILE native/guile.c` plus `$(pkg-config --cflags --libs guile-3.0)` to
the compilation command. The host reports its compiled capabilities through the
protocol; phmake then includes `guile` in `.FEATURES`. Scheme values are converted
to make words, and `gmk-expand`, `gmk-eval`, and `gmk-var` call back into PHP.
The CI image enables Guile in both the reference GNU make and phmake.
