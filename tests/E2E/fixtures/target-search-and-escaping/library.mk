.LIBPATTERNS = lib%.a
all: -lapp; @echo all=$^
-l%: lib%.a; @echo ignored
libapp.a:; @touch $@
