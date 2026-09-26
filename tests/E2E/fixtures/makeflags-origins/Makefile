$(info before=$(origin MAKEFLAGS):$(flavor MAKEFLAGS):$(MAKEFLAGS)|$(origin MFLAGS):$(MFLAGS))
MAKEFLAGS += -s
$(info after=$(origin MAKEFLAGS):$(MAKEFLAGS)|$(origin MFLAGS):$(MFLAGS))
all:
	@$(info build=$(origin MAKEFLAGS):$(MAKEFLAGS)|$(origin GNUMAKEFLAGS):$(flavor GNUMAKEFLAGS)) true
