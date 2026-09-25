.SUFFIXES:
all: first.out second
first.out: VALUE = rejected
second: VALUE = accepted
second: shared.mid
%.out: shared.mid absent
	@echo rejected
%.out: %.src
	@echo output:$<
%.mid: %.src
	@echo intermediate:$(VALUE)
	@touch $@
