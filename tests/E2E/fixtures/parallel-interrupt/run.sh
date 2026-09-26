set -eu
mkdir temp
TMPDIR="$PWD/temp" MAKE_TMPDIR="$PWD/temp" ./phmake --no-print-directory -rR -j2 -O > output.txt 2>&1 &
pid=$!
status=0
wait "$pid" 2>/dev/null || status=$?
test "$status" -eq 143
test ! -e partial
test -f precious
test ! -e completed
test ! -e late
test -z "$(ls -A temp)"
grep -q Terminated output.txt
echo interrupted-and-cleaned
