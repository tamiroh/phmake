unexport CYCLE
all: ; @printf '%s\n' "$$INHERITED" "$${CYCLE-unset}"
