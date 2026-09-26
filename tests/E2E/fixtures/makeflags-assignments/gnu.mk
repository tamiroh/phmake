GNUMAKEFLAGS = -s
$(info parse=$(MAKEFLAGS)|$(origin GNUMAKEFLAGS))
all:; $(info build=$(MAKEFLAGS)|$(origin GNUMAKEFLAGS))
