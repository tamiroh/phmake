all:
	@printf '<%s>\n' '$(intcmp +0002,2)' '$(intcmp -999999999999999999999999,0,less,$(error unreachable))' '$(intcmp 3,2,less,equal)' '$(intcmp 3,2,less,equal,)' '$(intcmp 2,3,$(info chosen)yes,$(error unreachable))'
bad: ; @echo $(intcmp nope,1)
