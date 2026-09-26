ifeq ($(LEVEL),)
all:; @$(MAKE) --no-print-directory -f shell.mk LEVEL=child
else ifeq ($(LEVEL),child)
output := $(shell $(MAKE) --no-print-directory -f shell.mk LEVEL=leaf)
all:; @echo $(output)
else
all:; @echo leaf $(LITERAL)
endif
