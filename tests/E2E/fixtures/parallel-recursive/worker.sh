set -eu
lock() {
    while ! mkdir lock 2>/dev/null; do sleep 0.01; done
}
lock
active=$(cat active 2>/dev/null || echo 0)
active=$((active + 1))
echo "$active" > active
maximum=$(cat maximum 2>/dev/null || echo 0)
if test "$active" -gt "$maximum"; then echo "$active" > maximum; fi
rmdir lock
test "$active" -le 2
attempt=0
until test "$(cat maximum)" -ge 2; do
    attempt=$((attempt + 1))
    test "$attempt" -lt 100
    sleep 0.05
done
sleep 0.05
lock
echo "$(($(cat active) - 1))" > active
echo "$1" >> completed
rmdir lock
