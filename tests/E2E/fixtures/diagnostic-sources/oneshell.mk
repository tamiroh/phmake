.ONESHELL:
SHELL = /bin/sh
.SHELLFLAGS = -ec
all:
	@echo before
	false
	echo after
