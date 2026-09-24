all: one.out two.out
one.out two.out: %.out: %.in | prepare
	@printf '<%s>\n' '$*|$<|$^|$||$?'
	@cp $< $@
.PHONY: prepare
prepare: ; @echo prepare
