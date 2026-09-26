$(info before=$(MAKEFLAGS)|$(origin MAKEOVERRIDES))
undefine MAKEOVERRIDES
MAKEFLAGS += -r
$(info after=$(MAKEFLAGS)|$(origin MAKEOVERRIDES))
all:; @$(info build=$(MAKEFLAGS)|$(origin MAKEOVERRIDES)) true
