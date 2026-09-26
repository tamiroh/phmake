MAKEFLAGS += -r
$(info parse=$(MAKEFLAGS)|$(origin CC)|$(if $(SUFFIXES),suffixes))
all:; @$(info build=$(MAKEFLAGS)|$(origin CC)|$(if $(SUFFIXES),suffixes)) true
