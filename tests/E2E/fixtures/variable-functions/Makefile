name = before
recursive = $(name)
simple := $(name)
recursive += $(name)
simple += $(name)
name = after
missing += $(name)
fallback ?= default
fallback ?= ignored
empty =
empty ?= ignored
name ?= ignored
loop = $(loop)
loop ?= $(loop)
pair = $(origin 1):$(flavor 1):$(value 1)
all:
	@printf '<%s>\n' '$(recursive)' '$(simple)' '$(missing)' '$(fallback)' '$(empty)'
	@printf '<%s>\n' '$(value recursive)' '$(value simple)' '$(value loop)' '$(value absent)'
	@printf '<%s>\n' '$(flavor recursive)' '$(flavor simple)' '$(flavor missing)' '$(flavor absent)'
	@printf '<%s>\n' '$(origin recursive)' '$(origin CC)' '$(origin MAKE)' '$(origin @)' '$(origin absent)'
	@printf '<%s>\n' '$(call value,recursive)' '$(call flavor,simple)' '$(call origin,@)'
	@printf '<%s>\n' '$(foreach name,a b,$(origin name):$(flavor name):$(value name))' '$(origin name):$(name)'
	@printf '<%s>\n' '$(call pair,$$(literal))'
environment:
	@printf '<%s>\n' '$(origin PHMAKE_SAMPLE_ENV):$(PHMAKE_SAMPLE_ENV)' '$(origin fallback):$(fallback)' '$(origin name):$(name)'
