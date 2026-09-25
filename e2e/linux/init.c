#include <stdio.h>
#include <string.h>
#include <sys/reboot.h>
#include <sys/utsname.h>
#include <unistd.h>

int main(void)
{
    struct utsname kernel;

    if (getpid() != 1 || uname(&kernel) != 0
        || strcmp(kernel.release, "6.12.0") != 0
        || strcmp(kernel.machine, "x86_64") != 0) {
        fputs("Linux build smoke test failed\n", stderr);
        return 1;
    }

    puts("Linux 6.12.0 x86_64 build smoke test passed");
    fflush(stdout);
    reboot(RB_AUTOBOOT);
    perror("reboot");
    return 1;
}
