all:
	@echo '$(VALUE):$(MAKE_RESTARTS):$(MAKEFILE_LIST)'
generated.mk:
	@echo 'VALUE = generated' > $@
