MAKEOVERRIDES =
.POSIX:
$(info parsed=$(MAKEFLAGS))
all:
	@$(info built=$(MAKEFLAGS))
	+@$(MAKE) --no-print-directory -f child.mk
