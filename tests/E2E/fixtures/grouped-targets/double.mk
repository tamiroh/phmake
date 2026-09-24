all: a b c d e
.PHONY: all a b c d e
a b c &:: ; @echo first:$@
c d e &:: ; @echo second:$@
