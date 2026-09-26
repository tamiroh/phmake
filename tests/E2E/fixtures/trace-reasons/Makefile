.PHONY: phony
all: result
result: input input | order
	@echo result; touch result
input order:
	@touch $@
phony: result
	@echo phony
forced:
	@echo forced
empty:
	$(EMPTY)
none: ;
