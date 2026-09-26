$(info parse=$(origin MAKEFLAGS):$(MAKEFLAGS))
MAKEFLAGS += -k
$(info assigned=$(origin MAKEFLAGS):$(MAKEFLAGS))
all:; $(info build=$(origin MAKEFLAGS):$(MAKEFLAGS))
