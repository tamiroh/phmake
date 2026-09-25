.SUFFIXES:
POLICY = .SECONDARY
FILES = item.mid
$(POLICY): $(FILES)

%.out: %.mid
	@echo output
	@cp -p $< $@
%.mid: %.in
	@echo intermediate
	@cp -p $< $@
