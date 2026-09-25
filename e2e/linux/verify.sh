#!/bin/sh
set -eu

cd /opt/linux
# Both configuration and recursive Kbuild invocations use the PATH's phmake.
make ARCH=x86_64 KCONFIG_ALLCONFIG=/opt/kernel.config allnoconfig
make ARCH=x86_64 bzImage
test -s vmlinux
test -s arch/x86/boot/bzImage

boot_log=$(mktemp)
trap 'rm -f "$boot_log"' EXIT
# TCG works on hosted runners without /dev/kvm. PID 1 reboots on success;
# -no-reboot makes that terminate QEMU instead of booting a second time.
boot_status=0
timeout 120 qemu-system-x86_64 \
    -accel tcg -m 128M -smp 1 -display none -monitor none -serial stdio \
    -no-reboot -nic none \
    -kernel arch/x86/boot/bzImage \
    -append 'console=ttyS0 rdinit=/init panic=-1' > "$boot_log" 2>&1 || boot_status=$?
cat "$boot_log"
test "$boot_status" -eq 0
tr -d '\r' < "$boot_log" | grep -Fx 'Linux 6.12.0 x86_64 build smoke test passed'
if grep -Fq 'Kernel panic' "$boot_log"; then
    exit 1
fi
