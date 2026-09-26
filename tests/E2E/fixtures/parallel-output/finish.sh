set -eu
attempt=0
until test -f done; do
    attempt=$((attempt + 1))
    test "$attempt" -lt 100
    sleep 0.05
done
sleep 0.1
echo left-end
