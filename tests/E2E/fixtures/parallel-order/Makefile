.PHONY: all a b c waited
all: a b c
	@echo $^
a b c:
	@mkdir occupied && sleep 0.05 && echo $@ && rmdir occupied
waited: a .WAIT b .WAIT c
	@echo $^
