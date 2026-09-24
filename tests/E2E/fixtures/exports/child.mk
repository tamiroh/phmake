all:
	@printf '<%s>\n' '$(origin INCLUDED):$(INCLUDED)' '$(origin LATE):$(value LATE)'
