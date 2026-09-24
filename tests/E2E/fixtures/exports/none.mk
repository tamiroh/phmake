export
PLAIN = plain
export EXPLICIT = kept
unexport
all:;@printf '<%s>\n' "$${PLAIN-unset}|$$EXPLICIT|$${PHMAKE_IMPORTED-unset}"
