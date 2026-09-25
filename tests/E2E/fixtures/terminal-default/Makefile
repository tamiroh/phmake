.SUFFIXES:
all: item.out
%.out:: %.mid
	@echo terminal:$<
%.mid: %.in
	@echo must-not-chain
.DEFAULT:
	@echo default:$@
