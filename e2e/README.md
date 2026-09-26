# Build comparisons

CI builds PHP, Lua, GNU make, Git, Linux, and FFmpeg with both GNU make and phmake.
Each project uses one Docker image and the same verification script for both
implementations. GNU make is the image's `/usr/bin/make`; its version is printed
in the log. The GNU make compatibility suite separately uses its pinned 4.4.1
reference executable.

From the repository root, for example:

```sh
docker build --platform linux/amd64 -f e2e/lua/Dockerfile -t phmake-lua-build .
python3 e2e/compare-builds.py lua
```

Replace `lua` with `php`, `make`, `git`, `linux`, or `ffmpeg` to run another project.
The comparison runner requires Python 3 and Docker on the host. It runs GNU make
first and phmake second, sequentially on the same host, each in a fresh container
with no network access. Build products are not shared. The container's `make`
symlink selects the implementation for direct and recursive invocations; build
targets, flags, and parallelism are identical for each pair.

Both implementations run even if the first fails. Any failure makes the runner
and CI job fail. Output is streamed to the job log. A table with exit status and
elapsed seconds is printed and appended to `GITHUB_STEP_SUMMARY` when available.
The phmake/GNU make time ratio is shown only when both runs pass.

The timer is monotonic and surrounds the entire `docker run`: it includes
container startup, build, installation where applicable, and smoke verification
(including QEMU boot for Linux). Image construction, source downloads, and
configure steps performed in the Dockerfile are excluded. These are single-run
end-to-end comparisons, not isolated make-engine benchmarks. Compiler work,
filesystem caches, the fixed run order, and CI runner load affect the results;
use repeated runs and make-only workloads when investigating smaller changes.

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

FFmpeg uses the upstream Makefiles with a limited codec configuration to keep
CI build times manageable. Its smoke test installs the binaries, generates video
and audio, encodes them as FFV1/PCM in Matroska, and verifies decoded video hashes and audio samples
against the original sources. External codec libraries and network input are
not part of this configuration.
