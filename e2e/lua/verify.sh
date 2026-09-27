#!/bin/sh
set -eu

cd /opt/lua
phase clean-build make linux
phase no-op-rebuild make linux
touch src/lua.c
phase touched-rebuild make linux
./src/lua -v
./src/luac -v
./src/lua -e '
    assert(_VERSION == "Lua 5.4")
    assert(6 * 7 == 42)
    print("Lua build smoke test passed")
'
