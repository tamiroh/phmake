special%.out: special%.in
	@echo specific $@ $<
%.out: %.in
	@echo generic $@ $<
%.loop: %.loop.loop
	@echo unreachable
all: special-item.out empty.out phony.out
empty.out: ;
.PHONY: all phony.out
