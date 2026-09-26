include generated.mk
all:
	@echo flags=$(MAKEFLAGS)
	+@$(MAKE) -f child.mk
generated.mk:
	@echo VALUE=ready > $@
