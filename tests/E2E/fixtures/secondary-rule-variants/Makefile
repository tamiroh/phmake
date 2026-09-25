.SECONDEXPANSION:
.PHONY: all source
all: obj/a.out a.static report left right
obj/%.out: INPUT = source
%.out: $$(INPUT)
	@echo '$@:$*:$<'
a_deps = source
a.static: %.static: $$($$*_deps)
	@echo '$@:$*:$<'
source:
	@echo source
report:: $$(info first $$@ has [$$<]) source
	@echo first-report
report:: $$(info second $$@ has [$$<]) source
	@echo second-report
left right &: $$(info expanding $$@) source
	@echo group:$@
