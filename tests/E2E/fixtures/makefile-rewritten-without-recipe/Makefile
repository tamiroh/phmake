.SECONDEXPANSION:
TEXT = X = 1
all: ; @echo restarts=$(MAKE_RESTARTS) X=$(X)

# Rewriting a makefile without a recipe does not restart make.
Makefile: $$(file >>Makefile,$$(TEXT))
