define BODY
-false
false
echo unreachable
endef
stop: ; @$(BODY)
ignore: ; -@$(BODY)
CYCLE = $(CYCLE)
expand-first:
	@touch started
	@echo $(CYCLE)
