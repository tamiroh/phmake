include settings.mk
all:
	@echo 'main:$(VALUE):$(.INCLUDE_DIRS)'
	@echo '$(MAKEFILE_LIST)'
	@$(MAKE) --no-print-directory -f child.mk
