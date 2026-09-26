# Linux build and boot test

From the repository root:

```sh
docker build --platform linux/amd64 -f e2e/linux/Dockerfile -t phmake-linux-build .
python3 e2e/compare-builds.py linux
```

The image pins Linux 6.12 and verifies the archive against its SHA-256 from
[kernel.org](https://cdn.kernel.org/pub/linux/kernel/v6.x/sha256sums.asc).
Both configuration and the kernel build use the selected make, including recursive
invocations. CI runs both GNU make and phmake in the `build-linux` job; an unsupported Kbuild feature
fails the job so it can become the next compatibility target.

`kernel.config` supplies a small x86_64 configuration to `allnoconfig`. It enables
a serial console and a built-in initramfs containing a statically linked `/init`.
This exercises Kbuild without compiling a distribution's drivers or modules.

The test requires `vmlinux` and `arch/x86/boot/bzImage`, then boots the resulting
kernel in QEMU using software emulation (TCG); KVM and privileged containers are
not required. PID 1 verifies the kernel release and architecture with `uname`,
prints `Linux 6.12.0 x86_64 build smoke test passed`, and reboots. QEMU's
`-no-reboot` turns that reboot into an exit. The verifier requires a successful
QEMU exit and the exact success line, rejects a kernel panic, and times out
after 120 seconds. This verifies a boot into userspace, not driver or hardware
coverage. Boot output is printed in the CI log; no artifacts are uploaded.

To run only the GNU make reference build:

```sh
docker run --rm --init --platform linux/amd64 --network none \
  -e MAKE_IMPLEMENTATION=gnu phmake-linux-build
```

Omit `MAKE_IMPLEMENTATION` for phmake. See [build comparisons](../README.md) for
the CI timing table; its elapsed time includes kernel configuration and QEMU boot.

The image packages phmake as an executable PHAR with an absolute PHP interpreter
path. Kbuild invokes `MAKE` from shell scripts as well as Makefile recipes, so
`MAKE` must identify one executable that those scripts can invoke directly.
