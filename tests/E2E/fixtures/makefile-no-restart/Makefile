all: stable.mk
	@echo 'done:$(MAKE_RESTARTS)'
include stable.mk
stable.mk: force
	@echo checked
.PHONY: force skipped.mk
-include skipped.mk repeated.mk
skipped.mk:
	@echo wrong-phony
repeated.mk::
	@echo wrong-double-colon
