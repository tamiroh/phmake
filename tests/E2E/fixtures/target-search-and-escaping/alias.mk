.SECONDEXPANSION:
vpath item.c source
all: item.c; @echo all=$^
item.c: $$(info alias prerequisites); @echo ignored
source/item.c: $$(info real prerequisites); @echo selected=$@
