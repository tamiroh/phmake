pid := $(shell echo $$PPID)
.PHONY: all late
.PRECIOUS: precious
all: partial precious late
partial:
	@touch partial; while test ! -f precious; do sleep 0.01; done; kill -TERM $(pid); sleep 10
precious:
	@touch precious; sleep 10; touch completed
late:
	@touch late
