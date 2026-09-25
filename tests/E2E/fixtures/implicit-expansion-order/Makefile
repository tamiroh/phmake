.SUFFIXES:
.SECONDEXPANSION:
all: item.out
%.out: unavailable $$(error must not expand a rejected rule)
	@echo rejected
%.out: %.in $$(info parent $$@)
	@echo output:$<
%.in: $$(info child $$@)
	@echo input:$@
%.unused: $$(error must not expand an unrelated rule)
	@echo unrelated
