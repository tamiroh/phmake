all:
	@echo 'stdin:$(VALUE):$(MAKE_RESTARTS)'
include generated.mk
generated.mk:
	@echo 'VALUE = generated' > $@
