FIXED := $(shell printf 'one\ntwo\n\n')
DEFERRED = $(shell printf '%s' '$(VALUE)')
VALUE = later
all:
	@printf '<%s>\n' '$(FIXED)' '$(DEFERRED)' '$(shell printf "a\r\nb\n")'
