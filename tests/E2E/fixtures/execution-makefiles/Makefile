MAKE = ./phmake
all: result
	@echo ready
include generated.mk
generated.mk:
	@$(MAKE) --no-print-directory -f generator.mk
result:
	@echo '$(VALUE)' > $@
