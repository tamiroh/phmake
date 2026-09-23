EMPTY =
SPACE = $(EMPTY) $(EMPTY)
COMMA = ,
BROKEN = $(BROKEN)
pair = $(0):$(1):$(2):$(3)
relay = $(call pair,$1)
walk = $(if $1,$(firstword $1) $(call walk,$(wordlist 2,$(words $1),$1)))

conditional:
	@printf '<%s>\n' '$(if ,$(BROKEN),safe)' '$(if yes,chosen,$(BROKEN))' '$(and ,$(BROKEN))' '$(or yes,$(BROKEN))'
	@printf '<%s>\n' '$(if $(SPACE),true,false)' '$(if $(EMPTY) ,true,false)' '$(or $(SPACE),fallback)' '$(and a, b )'
	@printf '<%s>\n' '$(if ,yes,no,more)' '${if yes,${or ,nested},unused}'

call:
	@printf '<%s>\n' '$(call relay,one,two,three)' '$(call pair,$$(literal),$(COMMA))' '$(call subst,a,b,a$(COMMA)a)' '$(call walk,a b c)'
	@printf '<%s>\n' '$(call if,yes,$(info first-argument)chosen,$(info second-argument)unused)'

order:
	@echo first
	@echo $(or $(info expanded),second)

cached:
	$(info must-not-expand)
	@echo must-not-run
