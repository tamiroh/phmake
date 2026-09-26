all:;
include generated.a
%.a %.b:; touch $*.a
