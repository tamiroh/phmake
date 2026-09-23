%.result: %.missing
	@echo wrong pattern
%.result: %.source
	@printf '%s\n' '$@ from $< stem $* deps $^' > $@
%.source: %.input
	@cp $< $@
all: build/item.result
build/item.source: build/item.input
.PHONY: all
