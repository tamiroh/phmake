all: output
output: input | prepare
	@echo rebuild
	@cp input output
.PHONY: prepare
prepare: ; @echo prepare
promoted: input | prepare input prepare
promoted: prepare
	@printf '<%s>\n' '$^|$+|$|'
