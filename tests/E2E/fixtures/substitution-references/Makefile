OBJECTS = a.lo b.lo unchanged
NAME = OBJECTS
SUFFIX = .o
all:
	@printf '%s\n' '$(OBJECTS:.lo=.o)' '${OBJECTS:%.lo=build/%.o}' '$($(NAME):.lo=$(SUFFIX))'
