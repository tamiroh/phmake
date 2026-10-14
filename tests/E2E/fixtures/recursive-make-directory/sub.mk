show:
	@[ "$$PWD" -ef . ] && echo pwd=ok
	@printf '%s|[%s]|[%s]\n' '$(notdir $(CURDIR))' '$(WORDS)' '$(QUOTE)'

fail:
	@exit 3
