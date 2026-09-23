$(info parsed)
VALUE := $(info immediate)hello
LAZY = $(info deferred)world
all:
	$(info recipe: $(VALUE) $(LAZY))
	echo visible
recurse:
	$(MAKE) child
