-include absent.mk
sinclude missing*.mk
include settings.mk
all: output
output: input*.txt
	@printf '%s\n' '$(MESSAGE): $^' > $@
-include generated.dep
