# Build comparisons

CI builds PHP, Lua, GNU make, Git, Linux, FFmpeg, and GCC with both GNU make and phmake.
Each project uses one Docker image and the same verification script for both
implementations. GNU make is the image's `/usr/bin/make`; its version is printed
in the log. The GNU make compatibility suite separately uses its pinned 4.4.1
reference executable.

From the repository root, for example:

```sh
docker build --platform linux/amd64 -f e2e/lua/Dockerfile -t phmake-lua-build .
python3 e2e/compare-builds.py lua /tmp/lua-results.json
python3 e2e/summarize-builds.py /tmp/lua-results.json
```

Replace `lua` with `php`, `make`, `git`, `linux`, `ffmpeg`, or `gcc` to run another project.
The comparison runner requires Python 3 and Docker on the host. It runs GNU make
first and phmake second, sequentially on the same host, each in a fresh container
with no network access. Build products are not shared. The container's `make`
symlink selects the implementation for direct and recursive invocations; build
targets, flags, and parallelism are identical for each pair.

Both runs must pass. Results contain exit status and elapsed time, including
container startup and smoke verification. Per-phase timings cover `clean-build`,
`no-op-rebuild`, and `touched-rebuild`. These are end-to-end measurements, not
isolated make-engine benchmarks.

To run only one implementation:

```sh
# Default: phmake
docker run --rm --init --platform linux/amd64 --network none phmake-lua-build

# GNU make, with the same build and verification
docker run --rm --init --platform linux/amd64 --network none \
  -e MAKE_IMPLEMENTATION=gnu phmake-lua-build
```

`MAKE_IMPLEMENTATION` accepts `phmake` (the default) or `gnu`. Select it through
the image's default command, which runs `e2e/run-build.sh` before the project
verifier. Overriding the command to call a verifier directly bypasses selection.

## Profiles

The profile image uses Excimer to sample phmake during `no-op-rebuild` and
`touched-rebuild`; the clean build uses GNU make.

```sh
docker build -f e2e/make/Dockerfile -t phmake-make-build .
docker build -f e2e/profile/Dockerfile \
  --build-arg BASE_IMAGE=phmake-make-build -t phmake-make-profile .
docker run --rm --init --network none -v /tmp/profiles:/profiles phmake-make-profile
python3 e2e/summarize-profiles.py make /tmp/profiles
```

The images are built for the host architecture here to avoid emulation; add
`--platform linux/amd64` to match CI. `summarize-profiles.py` prints the
functions with the most self and inclusive time per phase. speedscope can open
the `.folded` files as flame graphs. Samples are summed over processes, so a
parent waiting for a recursive make overlaps with the child. A process that
restarts itself through `pcntl_exec` loses the samples taken before the restart.
