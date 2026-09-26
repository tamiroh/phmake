$(info before=$(VALUE)|$(MAKEOVERRIDES))
MAKEFLAGS += -r
$(info after=$(VALUE)|$(MAKEOVERRIDES))
all:; @true
