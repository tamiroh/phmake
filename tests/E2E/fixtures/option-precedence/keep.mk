MAKEFLAGS += $(LOCAL_FLAGS)
$(info parse=$(MAKEFLAGS))
.PHONY: all fail after
all: fail after
fail:; @exit 1
after:; @echo after
