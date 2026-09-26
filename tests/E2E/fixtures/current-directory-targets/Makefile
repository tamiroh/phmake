all: ./out/thing.s ./single
./%.s: LABEL = source
./%.s: ./%.c FORCE
	@echo build:$@ from:$< $(LABEL)
	@cp $< $@
./single:
	@echo single:$@
	@touch $@
.PHONY: all FORCE
FORCE:

./static.out: ./%: ./input
	@echo static:$@ stem:$* from:$<
	@touch $@
