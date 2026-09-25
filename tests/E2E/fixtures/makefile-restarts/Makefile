STATE += once
$(info reading $(MAKE_RESTARTS):$(STATE))
all:
	@echo 'value=$(VALUE) arg=$(ARG) restarts=$(MAKE_RESTARTS) state=$(STATE)'
	@echo "exported=$$MAKE_RESTARTS"
include first.mk
first.mk:
	@echo generate-first
	@printf 'include second.mk\n' > $@
second.mk:
	@echo generate-second
	@printf 'VALUE = ready\n' > $@
