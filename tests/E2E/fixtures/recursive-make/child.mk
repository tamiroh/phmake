VALUE := child default
PLATFORM = generic
first:
	@$(MAKE) result PLATFORM=linux
result:
	@printf 'result=%s|%s|%s\n' '$(VALUE)' '$(PLATFORM)' "$$VALUE"
