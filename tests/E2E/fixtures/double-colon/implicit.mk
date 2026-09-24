all: source.out
source.out:: extra
%.out: %.in
	@echo $<:$^
	@cp $< $@
