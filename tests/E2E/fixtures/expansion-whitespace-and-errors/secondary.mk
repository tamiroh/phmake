.SECONDEXPANSION:
all: $$(eval $$(RULE))
define RULE
unexpected: missing
endef
