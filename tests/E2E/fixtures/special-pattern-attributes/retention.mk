.NOTINTERMEDIATE: %.mid
all: x.out
%.out: %.mid; @cp $< $@
x.%: %.in; @cp $< $@
