VALUE = before
LATE = $(VALUE)
EARLY := $(VALUE) $$literal
VALUE = after
all: first | directory
all: second
	@echo ran
first second directory:
%.result: %.input
	@echo $<
all: LOCAL := $(VALUE)
broken::
	@false
broken::
	@true
