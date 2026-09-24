VALUE = file
APPEND = file
FIXED = file
FALLBACK ?= fallback
override APPEND += forced
override EXPORTED = from-file
export EXPORTED
all:
	@printf '%s\n' '$(VALUE)|$(origin VALUE)|$(APPEND)|$(origin APPEND)|$(FIXED)|$(flavor FIXED)|$(FALLBACK)' "export=$$EXPORTED"
parent:
	@printf '%s\n' 'parent=$(MAKELEVEL)|$(VALUE)|$(origin VALUE)|$(FIXED)|$(flavor FIXED)'
	@$(MAKE) --no-print-directory -f child.mk child
builtins:
	@printf '%s\n' '$(origin CC)|$(origin COMPILE.c)|$(SHELL)'
