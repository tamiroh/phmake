export
PLAIN = plain
unexport HIDDEN
HIDDEN = hidden
PHMAKE_IMPORTED = changed
all:;@printf '<%s>\n' "$$PLAIN|$${HIDDEN-unset}|$$PHMAKE_IMPORTED"
