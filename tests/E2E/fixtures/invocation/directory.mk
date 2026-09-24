all:
	@printf '%s\n' '$(notdir $(CURDIR))|$(MAKECMDGOALS)'
