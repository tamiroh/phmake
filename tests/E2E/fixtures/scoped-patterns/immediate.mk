X = global
Y = early
a: X = local
a: SNAP := $(X)
a:
	@echo '$(X):$(SNAP)'
%.x: V := $(Y)
%.x: V += $(Y)
%.x:
	@echo '$(V):$(flavor V)'
Y = late
