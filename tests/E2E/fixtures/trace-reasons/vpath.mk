VPATH = search
all: output
output: distant
	@echo $<; touch $@
