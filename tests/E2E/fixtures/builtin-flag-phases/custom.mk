SUFFIXES = custom
CC = custom-cc
MAKEFLAGS += -R
$(info parse=$(CC)|$(SUFFIXES))
all:; @$(info build=$(CC)|$(SUFFIXES)) true
