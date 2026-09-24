failure = $(error stopped)
all: prerequisite
	@echo $(call failure)
prerequisite:
	@echo prerequisite
