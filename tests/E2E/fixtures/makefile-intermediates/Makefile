all: result
include stable.mk
stable.mk: temporary
	@echo unchanged
temporary: source
	@echo payload > $@
.INTERMEDIATE: temporary
result: temporary
	@cat $<
	@touch $@
