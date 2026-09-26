.PHONY: all cycle slow
all: left right
.INTERMEDIATE: common
left: common grouped-a
	@touch $@
right: common grouped-b
	@touch $@
common:
	@echo common >> counts; sleep 0.05; touch $@
grouped-a grouped-b &:
	@echo grouped >> counts; sleep 0.05; touch grouped-a grouped-b
cycle: a b
a: slow .WAIT b
	@echo a >> cycle.txt
b: a
	@echo b >> cycle.txt
slow:
	@sleep 0.01
.PHONY: stdin first-reader second-reader
stdin: first-reader second-reader
first-reader:
	@read line; test "$$line" = input; touch input-read; while test ! -f other-read; do sleep 0.01; done; echo first > first.txt
second-reader:
	@while test ! -f input-read; do sleep 0.01; done; if read line 2>/dev/null; then exit 1; fi; touch other-read; echo empty > second.txt
