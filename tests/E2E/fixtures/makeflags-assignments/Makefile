MAKEFLAGS += VALUE=file EXTRA=new
$(info parse=$(VALUE):$(origin VALUE)|$(EXTRA):$(origin EXTRA)|$(MAKEFLAGS))
all:; @$(info build=$(VALUE):$(origin VALUE)|$(EXTRA):$(origin EXTRA)|$(MAKEFLAGS)) true
