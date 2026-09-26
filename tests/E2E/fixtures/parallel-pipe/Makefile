.PHONY: all left right
all: left right
	@test "$$(cat maximum)" -eq 2; sort completed
left right:
	+@$(MAKE) --no-print-directory -f child.mk PREFIX=$@
