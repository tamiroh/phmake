set -eu
attempt=0
until test -f started; do
    attempt=$((attempt + 1))
    test "$attempt" -lt 100
    sleep 0.05
done
exit 1
