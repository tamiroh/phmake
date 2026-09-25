.PHONY: all a b shared
X = global
LIST = base
all: X = parent
all: a b
all a b shared:
	@echo '$@:$(X):$(LIST)'
a: X = first
a: LIST += $(X)
a: shared
b: X = second
b: shared
