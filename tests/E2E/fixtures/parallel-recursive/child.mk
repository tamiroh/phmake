.PHONY: all a b
all: a b
a b:
	@sh worker.sh $(PREFIX)-$@
