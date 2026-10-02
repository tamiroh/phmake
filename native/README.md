# Native module host

The optional host enables GNU make's `load` directive and Guile functions.

On Linux, build it with:

```sh
cc -std=c11 -O2 -Wall -Wextra -Werror -Wl,--export-dynamic \
  -o /tmp/phmake-module-host native/module-host.c -ldl
PHMAKE_MODULE_HOST=/tmp/phmake-module-host php phmake
```

Without `PHMAKE_MODULE_HOST`, phmake looks for
`/usr/local/libexec/phmake-module-host`.

For optional Guile support, install the Guile 3.0 development package and add
`-DPHMAKE_GUILE native/guile.c` plus `$(pkg-config --cflags --libs guile-3.0)` to
the compilation command. The GNU make E2E image enables both load and Guile support.
