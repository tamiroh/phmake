.PHONY: all first.x first-special.x
all: first.x first-special.x
WORD = global
LIST = root
%.x: WORD = general
%-special.x: WORD = specific
%.x: LIST += $(WORD)
first.x: override WORD = exact
first.x: export EXPORTED = $(WORD)
first.x first-special.x:
	@echo '$@:$(WORD):$(LIST)' "$$EXPORTED"
