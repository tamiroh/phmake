all: dir/sample.o result.out tool

.c.o:
	@printf 'compile %s -> %s (%s)\n' '$<' '$@' '$*'
	@cp $< $@

.in.out:
	@printf 'convert %s -> %s\n' '$<' '$@'
	@cp $< $@

.in:
	@printf 'single %s -> %s\n' '$<' '$@'
	@cp $< $@

.SUFFIXES:
.SUFFIXES: .c .o .in .out
