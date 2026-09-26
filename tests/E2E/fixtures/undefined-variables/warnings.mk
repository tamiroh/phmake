EMPTY =
REF = $(MISSING)
IMMEDIATE := $(REF)
NAME = ABSENT
NESTED := $($(NAME))
SUBST := $(UNSET:.c=.o)
INSPECT := $(origin INSPECT_MISSING) $(flavor INSPECT_MISSING) $(value INSPECT_MISSING)
LAZY := $(if yes,chosen,$(NOT_EVALUATED))
ifdef IFDEF_MISSING
$(error should not enter)
endif
CALL := $(call CALL_MISSING)
all:
	@echo recipe=$(REF) empty=$(EMPTY)
