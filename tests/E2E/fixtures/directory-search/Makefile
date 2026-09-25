VPATH = source
GPATH = $(KEEP)
.SUFFIXES:
all: result
result: item
	@echo output:$^:$?
	@cp -p $< $@
item: input
	@echo update:$@:$<
	@cp -p $< $@
