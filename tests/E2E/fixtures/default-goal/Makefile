first: ; @echo wrong
ifneq ($(.DEFAULT_GOAL),first)
$(error wrong initial default)
endif
.DEFAULT_GOAL :=
second: ; @echo wrong
ifneq ($(.DEFAULT_GOAL),second)
$(error wrong reset default)
endif
.DEFAULT_GOAL := chosen
chosen: ; @echo chosen
