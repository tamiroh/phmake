#!/bin/bash

make_output=$(mktemp)
phmake_output=$(mktemp)

targets=("foo" "bar" "baz")

for target in "${targets[@]}"; do
  make "$target" > "$make_output" 2>&1
  if [ $? -ne 0 ]; then
    echo "GNU Make command for target '$target' returned an error."
    exit 1
  fi

  ../phmake "$target" > "$phmake_output" 2>&1
  if [ $? -ne 0 ]; then
    echo "phmake command for target '$target' returned an error."
    exit 1
  fi

  if diff -q "$make_output" "$phmake_output" > /dev/null; then
    echo "'$target': OK"
  else
    echo "Output for target '$target' does NOT match between GNU Make and phmake. Showing results:"
    echo "GNU Make ------------------"
    cat "$make_output"
    echo "phmake --------------------"
    cat "$phmake_output"
    exit 1
  fi
done

echo "All targets passed the test with matching outputs."
