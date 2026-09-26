override MAKEFLAGS = -s
$(info parse=$(origin MAKEFLAGS):$(MAKEFLAGS))
all:; $(info build=$(origin MAKEFLAGS):$(MAKEFLAGS))
