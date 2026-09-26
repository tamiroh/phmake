#!/bin/sh
set -eu

cd /opt/gcc-build
make -j2 MAKE=/usr/local/bin/make all
make -j2 MAKE=/usr/local/bin/make install

PATH=/opt/gcc-install/bin:$PATH
export PATH
test "$(command -v gcc)" = /opt/gcc-install/bin/gcc
test "$(command -v g++)" = /opt/gcc-install/bin/g++
test "$(gcc -dumpfullversion)" = 15.2.0
test "$(g++ -dumpfullversion)" = 15.2.0
gcc --version
g++ --version

smoke_dir=$(mktemp -d)
trap 'rm -rf "$smoke_dir"' EXIT

cat > "$smoke_dir/sample.c" <<'C'
#include <stdio.h>

int main(void)
{
    int sum = 0;
    for (int i = 1; i <= 10; ++i)
        sum += i;
    printf("%d\n", sum);
    return sum != 55;
}
C

gcc -std=c17 -O2 -Wall -Wextra -Werror "$smoke_dir/sample.c" -o "$smoke_dir/sample-c"
test "$("$smoke_dir/sample-c")" = 55

cat > "$smoke_dir/sample.cpp" <<'CPP'
#include <algorithm>
#include <iostream>
#include <numeric>
#include <stdexcept>
#include <vector>

int main()
{
    std::vector<int> values{3, 1, 4, 2};
    std::sort(values.begin(), values.end());
    try {
        if (values.front() == 1 && values.back() == 4)
            throw std::runtime_error("ok");
    } catch (const std::runtime_error& error) {
        std::cout << error.what() << ':'
                  << std::accumulate(values.begin(), values.end(), 0) << '\n';
        return 0;
    }
    return 1;
}
CPP

g++ -std=c++17 -O2 -Wall -Wextra -Werror -static-libstdc++ -static-libgcc \
    "$smoke_dir/sample.cpp" -o "$smoke_dir/sample-cpp"
test "$("$smoke_dir/sample-cpp")" = 'ok:10'

echo 'GCC build smoke test passed'
