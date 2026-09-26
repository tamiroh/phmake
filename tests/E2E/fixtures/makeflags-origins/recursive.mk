MAKEFLAGS += -s
all:; +@$(MAKE) --no-print-directory -f child.mk
