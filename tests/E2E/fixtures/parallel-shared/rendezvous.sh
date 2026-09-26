set -eu
touch "$1.started"
attempt=0
until test -f left.started && test -f right.started; do
    attempt=$((attempt + 1))
    test "$attempt" -lt 100
    sleep 0.05
done
printf '%s\n' "$2" > "$1.value"
