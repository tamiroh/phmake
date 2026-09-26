function = $(1) $(2)
WRAPPED = $(call function,$(1))
all:
	@echo $(call WRAPPED,value)
