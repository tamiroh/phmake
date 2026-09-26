ifeq ($(MAKE_RESTARTS),)
$(file >pid.txt,$(shell echo $$PPID))
endif
include generated.mk
all:; @test "$(shell echo $$PPID)" = "$(file <pid.txt)" && echo same-process:$(VALUE):$(MAKE_RESTARTS)
generated.mk:; @echo VALUE=generated >$@
