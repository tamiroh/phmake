NAME = sample
define RULE
$(NAME): ; @echo generated $$(VALUE)
endef
$(eval $(RULE))
VALUE = later

define COMMANDS
@echo first
echo second
endef
all: sample commands muted state next
commands: ; $(COMMANDS)
muted: ; @$(COMMANDS)
state: ; @echo $(eval STATE := changed)$(STATE)
next: ; @echo $(STATE)

assign = $(eval $(1) := $(2))
$(foreach name,A B,$(call assign,$(name),value-$(name)))
values: ; @echo $(A) $(B) $(origin name)
