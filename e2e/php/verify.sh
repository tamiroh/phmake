#!/bin/sh
set -eu

cd /opt/php-src
phase clean-build make
phase no-op-rebuild make
touch sapi/cli/php_cli.c
phase touched-rebuild make
./sapi/cli/php -n -v
./sapi/cli/php -n -r '
    if (PHP_VERSION !== "8.5.0" || PHP_SAPI !== "cli" || 6 * 7 !== 42) {
        exit(1);
    }
    echo "PHP build smoke test passed\n";
'
