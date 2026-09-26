MAKEOVERRIDES =
$(info parsed=$(MAKEFLAGS))
all:; @$(info built=$(MAKEFLAGS)) true
