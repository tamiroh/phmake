export DIRECT = initial
export LATE EMPTY
LATE = $(DIRECT):$@
DIRECT = final
export NAMES
NAMES = DIRECT LATE
export $(NAMES)
unexport DIRECT
DIRECT = private
PHMAKE_IMPORTED = changed
unexport PHMAKE_REMOVED
PHMAKE_REMOVED = changed
include exports.mk
all:
	@printf '<%s>\n' "$${DIRECT-unset}|$$LATE|$${EMPTY-unset}|$$INCLUDED"
	@printf '<%s>\n' "$$PHMAKE_IMPORTED|$${PHMAKE_REMOVED-unset}"
	@printf '<%s>\n' '$(origin EMPTY):$(flavor EMPTY)' '$(DIRECT)'
child:
	@$(MAKE) -f child.mk
