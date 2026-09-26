# GNU make build and compatibility tests

Build the shared environment from the repository root:

```sh
docker build --platform linux/amd64 -f e2e/make/Dockerfile -t phmake-make-build .
```

The default command builds GNU make 4.4.1 with phmake and checks the resulting executable:

```sh
docker run --rm --platform linux/amd64 phmake-make-build
```

To run GNU make's own test suite against phmake:

```sh
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests
```

The image also contains an unmodified GNU make 4.4.1 reference executable, built
with the system make. Run the same suite against it to check the test environment:

```sh
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests --reference
```

Each invocation uses a fresh temporary copy of the test directory and the 128-file
descriptor limit used by GNU make 4.4.1's `check-regression` target. This limit is
required by tests that intentionally exhaust file descriptors. The runner does
not enable `-keep`: upstream cleanup must remove generated targets and synchronization
files between cases. Failed cases still retain their diagnostic files in `work/`.
The temporary directory is removed after optional result copying. `--init` reaps
orphaned descendants when a timed-out test is killed.

Append test categories to run a subset, for example:

```sh
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests variables/flavors
```

The runner exits nonzero when tests fail. Full compatibility is not yet implemented;
this command measures the current coverage rather than expecting a passing suite.
The summary reports executed test cases and passed, failed, and skipped categories.
Skipped categories and cases not reached after a category abort are not passed tests.
Run as an unprivileged user so permission tests are not skipped merely because the
container is running as root. Network access is not needed.

To save the failed categories’ generated Makefiles, logs, expected output, and diffs:

```sh
results_dir=$(mktemp -d)
chmod 777 "$results_dir"
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  -v "$results_dir:/results" -e PHMAKE_TEST_RESULTS=/results \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests \
  > "$results_dir/run.log" 2>&1
```

Inspect `run.log` and `work/` in that temporary directory after the command finishes.
Use a fresh results directory for each run. The image packages phmake and its runtime
dependencies in a PHAR with `/usr/local/bin/php` as its interpreter. This lets tests
copy the executable or change `PATH` without losing PHP or the autoloader.

To test local changes without rebuilding the image, package a fixed source copy:

```sh
snapshot_dir=$(mktemp -d)
cp -R src "$snapshot_dir/src"
mkdir "$snapshot_dir/output"
docker run --rm --platform linux/amd64 --network none \
  -v "$snapshot_dir/src:/opt/phmake/src:ro" \
  -v "$snapshot_dir/output:/output" phmake-make-build \
  php -d phar.readonly=0 /opt/phmake/tools/build-phar.php \
  /output/phmake.phar /usr/local/bin/php
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  -v "$snapshot_dir/output/phmake.phar:/opt/phmake/phmake:ro" \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests
```

Do not edit the source copy or PHAR during a run. Each invocation clears inherited
test work files before starting, so selected categories cannot retain old failures.

`test-runner.patch` adds an explicit `-phmake` mode to the upstream runner: it accepts
phmake's version identity and retains the executable path supplied with `-make`,
which also supports unpackaged phmake, whose `MAKE` variable contains PHP and the script path.
It also adds summary counts, including skipped categories. On Unix, each test
command starts in its own process group. A timeout kills that group and reaps the
direct child before the runner continues, while preserving the upstream timeout
failure status. `verify-runner.pl` checks this with a child and grandchild that ignore
ordinary termination signals; image construction fails if the check fails. Test
cases, expected outputs, and pass/fail comparisons are unchanged except for the
product-name assertion described below.

In CI, `build-make` runs the build smoke test and `test-gnu-make` runs the full
compatibility suite, first against the reference executable and then against phmake.
Test failures fail the compatibility job. Output is available
in the job log, and its job summary shows the counts even when tests fail.

The `options/dash-d` banner assertion uses the anchored `phmake (development)`
identity in phmake mode. The reference mode retains the original `GNU Make`
assertion. This is a product-name adaptation; the debug option combination and
requirement that its version banner be printed remain unchanged.

The image builds the optional [native module host](../../native/README.md) so the
upstream `features/load` and `features/loadapi` tests are enabled. The helper
implements the C plugin ABI; all Makefile evaluation continues to run in PHP.

Guile 3.0 is enabled in both executables. The `functions/guile` category runs in
CI as well; only the VMS-specific category is outside this Linux environment.
