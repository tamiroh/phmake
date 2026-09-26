.PHONY: failed ignored recursive
failed:
	@echo before
	@false
	@echo after
ignored:
	-@false
	@echo continued
recursive:
	@$(MAKE) --no-print-directory -f child.mk
