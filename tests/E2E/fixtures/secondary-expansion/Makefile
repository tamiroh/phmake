.SECONDEXPANSION:
.PHONY: all first second prepare unused
MODE = wrong
all: MODE = selected
selected_deps = first second
all: $$($$(MODE)_deps)
all: $$(info prior\:$$@/$$</$$^/$$+) | prepare
all:
	@echo 'result:$^|$|'
first second prepare:
	@echo $@
unused: $$(error unused prerequisites expanded)
