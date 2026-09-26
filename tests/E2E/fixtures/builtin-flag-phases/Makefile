$(info before=$(MAKEFLAGS)|$(origin CC)|$(if $(SUFFIXES),suffixes))
MAKEFLAGS += -R
$(info after=$(MAKEFLAGS)|$(origin CC)|$(if $(SUFFIXES),suffixes))
all:; @$(info build=$(MAKEFLAGS)|$(origin CC)|$(if $(SUFFIXES),suffixes)) true
