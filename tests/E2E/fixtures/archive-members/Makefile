all: lib.a( a.o b.o )
	@echo ready
(%): %
	@echo archive=$@ member=$% input=$<
	@ar rcU $@ $< >/dev/null 2>&1 || ar rc $@ $< >/dev/null 2>&1
check: lib.a(*.o)
	@echo members=$^ first='$<' newer=$?
.PHONY: all check
