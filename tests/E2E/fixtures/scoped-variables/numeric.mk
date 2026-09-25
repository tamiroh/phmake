2 = global
1: 2 = local
1:
	@echo '$(2):$(SNAP)'
1: SNAP := $(2)
2:
	@echo '$(2)'
