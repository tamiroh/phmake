MFLAGS = custom
MAKEFLAGS += -s
$(info parse=$(origin MFLAGS):$(MFLAGS))
all:; $(info build=$(origin MFLAGS):$(MFLAGS))
