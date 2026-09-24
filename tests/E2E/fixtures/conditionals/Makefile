EMPTY =
DEFINED = $(EMPTY)
NAME = DEFINED
CYCLE = $(CYCLE)
all:
ifdef $(NAME)
	@echo defined
else
	@echo wrong
endif
ifeq ($(strip a  b),a b)
	@echo equal
endif
ifneq 'a,b' "a,b"
	@echo wrong
else ifeq (no,yes)
	@echo wrong
else ifndef MISSING
	@echo selected
else
	@echo wrong
endif
ifeq (no,yes)
include missing.mk
INVALID := $(CYCLE)
.RECIPEPREFIX = >
ifdef $(CYCLE)
this is not makefile syntax
endif
else
include selected.mk
endif
after:
	@echo $(RESULT)
