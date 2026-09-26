$(info flavor=$(flavor MAKEFLAGS) origin=$(origin MAKEOVERRIDES))
$(info initial=$(MAKEFLAGS))
ifneq ($(MODE),keep)
MAKEOVERRIDES = $(REPLACEMENT)
endif
$(info parsed=$(MAKEFLAGS))
override VALUE = parent
all:
	@$(info built=$(MAKEFLAGS))
	+@$(MAKE) --no-print-directory -f child.mk
