private define HIDDEN
visible while reading
endef
SNAP := $(HIDDEN)
all: private export TEXT = first;\
second
all:
	@echo '$(SNAP)|$(HIDDEN)' "$$TEXT"
