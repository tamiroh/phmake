.PHONY: all a.one a.two
all: a.one a.two item.out
a.*:; @echo $@
%.out: %.i*; @echo $@=$^
