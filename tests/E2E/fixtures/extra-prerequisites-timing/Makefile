EXTRA = before
.EXTRA_PREREQS = $(EXTRA)
all: first second
first: ; @echo first$(eval EXTRA = after)
second: ; @echo second
before after: .EXTRA_PREREQS =
before after: ; @echo $@
