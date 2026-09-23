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
docker run --rm --platform linux/amd64 --network none --user nobody \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests
```

Append test categories to run a subset, for example:

```sh
docker run --rm --platform linux/amd64 --network none --user nobody \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests variables/flavors
```

The runner exits nonzero when tests fail. Full compatibility is not yet implemented;
this command measures the current coverage rather than expecting a passing suite.
The summary reports executed test cases and passed, failed, and skipped categories.
Skipped categories and cases not reached after a category abort are not passed tests.
Run as an unprivileged user so permission tests are not skipped merely because the
container is running as root. Network access is not needed.

To keep the generated Makefiles, expected output, actual output, and diffs:

```sh
results_dir=$(mktemp -d)
chmod 777 "$results_dir"
docker run --rm --platform linux/amd64 --network none --user nobody \
  -v "$results_dir:/results" -e PHMAKE_TEST_RESULTS=/results \
  phmake-make-build sh /usr/local/bin/run-gnu-make-tests \
  > "$results_dir/run.log" 2>&1
```

Inspect `run.log` and `work/` in that temporary directory after the command finishes.
Use a fresh results directory for each run. To test local changes without rebuilding
the image, add `-v "$PWD/src:/opt/phmake/src:ro"` to the Docker command.

`test-runner.patch` adds an explicit `-phmake` mode to the upstream runner: it accepts
phmake's version identity and retains the executable path supplied with `-make`,
because phmake's `MAKE` variable is a shell command containing PHP and the script path.
It also adds summary counts, including skipped categories. Test cases, expected
outputs, and pass/fail comparisons are unchanged.

In CI, `build-make` runs the build smoke test and `test-gnu-make` runs the full
compatibility suite. Test failures fail the compatibility job. Output is available
in the job log, and its job summary shows the counts even when tests fail.
