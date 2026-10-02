# GNU make build and compatibility tests

Build the shared environment from the repository root:

```sh
docker build --platform linux/amd64 -f e2e/make/Dockerfile -t phmake-make-build .
```

For project build comparisons, see [build comparisons](../README.md).

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

Append test categories to run a subset, for example:

```sh
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests variables/flavors
```

Failures produce a nonzero exit status. Full GNU make compatibility is not yet
implemented. Use `--user nobody` so permission tests run, and `--init` to reap
orphaned processes. Do not add `-keep`: cases rely on upstream cleanup.

To save the failed categories’ generated Makefiles, logs, expected output, and diffs:

```sh
results_dir=$(mktemp -d)
chmod 777 "$results_dir"
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  -v "$results_dir:/results" -e PHMAKE_TEST_RESULTS=/results \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests \
  > "$results_dir/run.log" 2>&1
```

Inspect `run.log` and `work/` in the results directory. Use a fresh directory
for each run.

To test local changes without rebuilding the image, package a fixed source copy:

```sh
snapshot_dir=$(mktemp -d)
cp -R src "$snapshot_dir/src"
cp phmake "$snapshot_dir/phmake"
mkdir "$snapshot_dir/output"
docker run --rm --platform linux/amd64 --network none \
  -v "$snapshot_dir/src:/opt/phmake/src:ro" \
  -v "$snapshot_dir/phmake:/opt/phmake/phmake:ro" \
  -v "$snapshot_dir/output:/output" phmake-make-build \
  sh -c 'rm -f /opt/phmake/dist/phmake.phar && /usr/bin/make -C /opt/phmake phar PHAR_INTERPRETER=/usr/local/bin/php && cp /opt/phmake/dist/phmake.phar /output/phmake.phar'
docker run --rm --init --platform linux/amd64 --network none --user nobody \
  -v "$snapshot_dir/output/phmake.phar:/opt/phmake/phmake:ro" \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests
```

Do not edit the source copy or PHAR during a run. Each invocation clears inherited
test work files before starting, so selected categories cannot retain old failures.

The image enables the [native module host](../../native/README.md) and Guile 3.0
for `features/load`, `features/loadapi`, and `functions/guile`.
