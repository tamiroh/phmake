.EXTRA_PREREQS = shared
.PHONY: all shared hidden source
all: result
result: .EXTRA_PREREQS = hidden
result: source; @echo "auto=$</$^/$?/$+/$|"; touch result
source shared hidden:; @echo $@
