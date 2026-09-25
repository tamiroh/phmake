.PHONY: all middle leaf
X = global
all: X = parent
all: middle
middle: private X = local
middle: private export ONLY_HERE = secret
middle: leaf
all middle leaf:
	@echo '$@:$(X)' "$$ONLY_HERE"
