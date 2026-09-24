include included.mk
message = $(warning through variable)
all: first second third
first:
	@echo first $(message)
second:
	@echo second $(foreach x,a b,$(call warning,item $x))
third:
	@echo $(if yes,safe,$(error unused))
