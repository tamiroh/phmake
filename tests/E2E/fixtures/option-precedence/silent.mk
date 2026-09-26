MAKEFLAGS += $(LOCAL_FLAGS)
$(info parse=$(MAKEFLAGS))
all:; $(info recipe=$(MAKEFLAGS))
