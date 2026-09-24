PRE := $(.SHELLSTATUS)
BAD := $(shell printf kept; exit 7)
STATUS := $(.SHELLSTATUS)
$(shell kill -2 $$$$)
SIGNAL := $(.SHELLSTATUS)
ASSIGNED != printf '$$(VALUE)\n\n'
VALUE = expanded
export EXPORTED = from-make
export RECURSIVE = $(shell printf '%s' "$$RECURSIVE")
ENV := $(shell printf '%s' "$$EXPORTED")
.SHELLFLAGS = -ec
all:
	@printf '<%s>\n' '$(PRE)|$(BAD)|$(STATUS)' '$(ASSIGNED)|$(flavor ASSIGNED)' '$(ENV)' '$(SIGNAL)' "$$RECURSIVE"
fail:
	@false; echo unreachable
cli: ; @printf '<%s>\n' '$(CLI)|$(flavor CLI)'
